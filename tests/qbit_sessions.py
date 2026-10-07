"""Exercise real cURL cookies against a localhost, session-scoped qBittorrent stub."""
from pathlib import Path
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from http.cookies import SimpleCookie
from urllib.parse import urlsplit, parse_qs
import base64
import json
import os
import secrets
import subprocess
import sys
import threading

root = Path(__file__).resolve().parents[1]
php = sys.argv[1] if len(sys.argv) > 1 else "php"
private = root / ".test-output" / ("qbit-sessions-" + secrets.token_hex(4))
private.mkdir(parents=True, mode=0o700)
state = {"sessions": set(), "jobs": {}, "logins": 0, "next_id": 1, "version_status": 200}


class Stub(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def reply(self, status, body, cookie=None):
        data = (json.dumps(body) if not isinstance(body, str) else body).encode()
        self.send_response(status)
        if cookie:
            self.send_header("Set-Cookie", f"SID={cookie}; Path=/; HttpOnly")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def dispatch(self):
        path = urlsplit(self.path)
        params = parse_qs(path.query)
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        if self.command == "POST":
            params = parse_qs(body.decode())
        action = path.path.removeprefix("/api/v2/")
        cookie = SimpleCookie(self.headers.get("Cookie", ""))
        sid = cookie["SID"].value if "SID" in cookie else None
        if action == "auth/login":
            sid = secrets.token_hex(16)
            state["sessions"].add(sid)
            state["logins"] += 1
            self.reply(200, "Ok.", sid)
            return
        if sid not in state["sessions"]:
            self.reply(403, "Forbidden")
            return
        if action == "app/version":
            self.reply(state["version_status"], "v5.1.2")
        elif action == "search/start":
            job = state["next_id"]
            state["next_id"] += 1
            state["jobs"][(sid, job)] = True
            self.reply(200, {"id": job})
        elif action == "search/results":
            job = int(params.get("id", ["0"])[0])
            if (sid, job) not in state["jobs"]:
                self.reply(404, "Not Found")
            else:
                self.reply(200, {"status": "Stopped", "results": [], "total": 0})
        else:
            self.reply(500, "Unexpected test endpoint")

    do_GET = dispatch
    do_POST = dispatch


server = ThreadingHTTPServer(("127.0.0.1", 0), Stub)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
env = os.environ.copy()
env.update({"APP_ENV": "local", "APP_URL": "http://127.0.0.1", "APP_KEY": base64.b64encode(secrets.token_bytes(32)).decode(),
            "STORAGE_PATH": str(private), "SCREENPORT_ENV_FILE": str(private / ".env"), "SCREENPORT_TEST_ROOT": str(root),
            "QBITTORRENT_URL": f"http://127.0.0.1:{server.server_port}", "QBITTORRENT_USERNAME": "fixture",
            "QBITTORRENT_PASSWORD": "fixture-password"})
(private / ".env").write_text("", encoding="utf-8")
code = r'''
require getenv('SCREENPORT_TEST_ROOT').'/app/bootstrap.php';
$action=getenv('SCREENPORT_TEST_ACTION');
$q=new ScreenPort\Qbit($settings,$action==='isolated_results' ? null : $config);
try {
    $value=$action==='start' ? ['id'=>$q->startSearch('Ubuntu test fixture')] : $q->results((int)getenv('SCREENPORT_TEST_ID'));
    echo json_encode($value,JSON_THROW_ON_ERROR);
} catch(RuntimeException $e) { echo json_encode(['error_code'=>$e->getCode()]); }
'''
checks = 0


def check(ok, label):
    global checks
    checks += 1
    if not ok:
        raise AssertionError(label)


def client(action, job=0):
    run_env = env | {"SCREENPORT_TEST_ACTION": action, "SCREENPORT_TEST_ID": str(job)}
    result = subprocess.run([php, "-r", code], env=run_env, cwd=root, capture_output=True, text=True, timeout=30, check=True)
    return json.loads(result.stdout)


try:
    first = client("start")["id"]
    check(state["logins"] == 1, "initial login")
    check(client("results", first).get("status") == "Stopped", "search survives a new PHP process")
    check(state["logins"] == 1, "saved session reused without another login")
    check(client("isolated_results", first).get("error_code") == 404, "old client behavior reproduces session-scoped 404")
    check(client("results", first).get("status") == "Stopped", "connection checks cannot overwrite worker session")
    check(state["logins"] == 2, "isolated check used separate login")
    state["sessions"].clear()
    check(client("results", first).get("error_code") == 404, "server restart invalidates old search")
    check(state["logins"] == 3, "expired session reauthenticated")
    fresh = client("start")["id"]
    check(client("results", fresh).get("status") == "Stopped", "new search works after expiration")
    env["QBITTORRENT_PASSWORD"] = "changed-fixture-password"
    check(client("results", fresh).get("error_code") == 404, "credential changes isolate sessions")
    check(state["logins"] == 4, "credential change authenticates a fresh session")
    changed = client("start")["id"]
    check(client("results", changed).get("status") == "Stopped", "changed credentials persist their own session")
    state["version_status"] = 503
    check(client("results", changed).get("error_code") == 0, "server errors stop instead of creating another login")
    check(state["logins"] == 4, "non-auth error does not reauthenticate")
    jars = list((private / "qbit").glob("*.cookies"))
    check(len(jars) == 2 and all(p.stat().st_size > 0 for p in jars), "cookies saved only under isolated private storage")
    if os.name != "nt":
        check(all(p.stat().st_mode & 0o777 == 0o600 for p in jars), "cookie files restricted to owner")
    print(f"PASS: {checks} session checks using localhost only; no real services or downloads contacted.")
finally:
    server.shutdown()
    server.server_close()
