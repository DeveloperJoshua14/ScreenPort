"""Test the real API against isolated SQLite and a fake local Jellyfin server."""
import base64
import http.cookiejar
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

root = Path(__file__).resolve().parents[1]
php = root / '.tools/php/php.exe'
private = root / '.test-output' / ('review-http-' + uuid.uuid4().hex[:8])
private.mkdir()
class Jellyfin(BaseHTTPRequestHandler):
    def log_message(self, *_): pass
    def do_GET(self):
        parsed = urllib.parse.urlsplit(self.path)
        query = urllib.parse.parse_qs(parsed.query)
        if parsed.path == '/Items':
            if self.headers.get('X-Emby-Token') != 'fake-private-jellyfin-key':
                self.send_error(401); return
            tv = query.get('IncludeItemTypes') == ['Series']
            body = {'Items': [{'Id': 'a' * 32, 'ServerId': 'b' * 32, 'Name': 'HTTP Sample Show' if tv else 'HTTP Sample Movie', 'Type': 'Series' if tv else 'Movie', 'ProviderIds': {'Tmdb': '902' if tv else '901'}}]}
        else: body = {'Id': 'b' * 32}
        self.send_response(200); self.send_header('Content-Type', 'application/json'); self.end_headers(); self.wfile.write(json.dumps(body).encode())

backend = ThreadingHTTPServer(('127.0.0.1', 0), Jellyfin)
threading.Thread(target=backend.serve_forever, daemon=True).start()
with socket.socket() as probe:
    probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
base = f'http://127.0.0.1:{port}'
env = os.environ.copy()
env.update({'SCREENPORT_ENV_FILE': str(private / '.env'), 'APP_ENV': 'local', 'APP_URL': base, 'APP_KEY': base64.b64encode(os.urandom(32)).decode(), 'STORAGE_PATH': str(private)})
(private / '.env').write_text(f'DOWNLOAD_MANAGER_EMAIL=manager@example.test\nJELLYFIN_URL=http://127.0.0.1:{backend.server_port}\nJELLYFIN_PUBLIC_URL=https://watch.example.test/jellyfin\nJELLYFIN_API_KEY=fake-private-jellyfin-key\nTMDB_READ_ACCESS_TOKEN=fake\nDOWNLOADS_ENABLED=false\n', encoding='utf-8')
subprocess.run([str(php), str(root / 'tests/library_review_fixture.php')], env=env, cwd=root, check=True, stdout=subprocess.DEVNULL)
log = (private / 'server.log').open('wb')
server = subprocess.Popen([str(php), '-S', f'127.0.0.1:{port}', '-t', 'public'], env=env, cwd=root, stdout=log, stderr=log)
checks = 0
def check(ok, message):
    global checks
    checks += 1
    if not ok: raise AssertionError(message)
def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(opener, action, body=None, token=None, origin=None):
    headers = {'Content-Type': 'application/json', 'Origin': origin or base}
    if token: headers['X-CSRF-Token'] = token
    req = urllib.request.Request(base + '/api.php?action=' + action, data=json.dumps(body).encode() if body is not None else None, headers=headers)
    try:
        with opener.open(req, timeout=10) as response: return response.status, json.load(response)
    except urllib.error.HTTPError as error: return error.code, json.load(error)
try:
    guest = client()
    for _ in range(60):
        try: _, session = request(guest, 'session'); break
        except urllib.error.URLError: time.sleep(.05)
    else: raise RuntimeError('Isolated PHP test server did not start')
    token = session['data']['csrf']
    status, _ = request(guest, 'library-review', {'type': 'movie', 'id': 901}, token)
    check(status == 401, 'reviews require signed-in account')
    status, _ = request(guest, 'library-review')
    check(status == 405, 'GET cannot create review')
    member = client(); _, session = request(member, 'session')
    status, login = request(member, 'login', {'username': 'reviewmember', 'password': 'FakeMemberPassword2026!'}, session['data']['csrf'])
    check(status == 200, 'approved member signs in')
    token = login['data']['csrf']
    status, _ = request(member, 'library-review', {'type': 'movie', 'id': 901})
    check(status == 403, 'review requires CSRF token')
    status, _ = request(member, 'library-review', {'type': 'movie', 'id': 901}, token, 'https://attacker.invalid')
    check(status == 403, 'foreign-origin review blocked')
    status, _ = request(member, 'library-review', {'type': 'invalid', 'id': 0}, token)
    check(status == 422, 'invalid review media rejected')
    status, detail = request(member, 'media&type=tv&id=902')
    check(status == 200 and detail['data']['library']['in_library'], 'TV details include library match')
    link = detail['data']['library']['url']
    check(link.startswith('https://watch.example.test/jellyfin/web/#/details?') and 'serverId=' in link, 'direct item link uses browser address')
    check('fake-private' not in json.dumps(detail) and '127.0.0.1' not in link, 'detail response excludes API credentials and private navigation host')
    status, catalog = request(member, 'catalog&type=all&q=HTTP%20Sample')
    check(status == 200 and all(m['library']['in_library'] for m in catalog['data']['results']), 'catalog matches both movie and series for green cards')
    status, review = request(member, 'library-review', {'type': 'movie', 'id': 901, 'email': 'attacker@example.test', 'user_id': 1, 'media': {'title': 'Forged title'}}, token)
    check(status == 200 and 'Confirmation' in review['data']['message'], 'review works while downloads paused')
    with sqlite3.connect(private / 'screenport.sqlite') as db:
        rows = db.execute('SELECT user_id,media FROM library_reviews').fetchall()
        check(len(rows) == 1 and rows[0][0] == 2 and 'HTTP Sample Movie' in rows[0][1] and 'Forged title' not in rows[0][1], 'identity and media come from server not posted fields')
        recipients = [r[0] for r in db.execute('SELECT recipient FROM manager_alerts ORDER BY id')]
        check(recipients == ['manager@example.test', 'member@example.test'], 'review emails go only to manager and authenticated member')
        check(db.execute('SELECT COUNT(*) FROM jobs').fetchone()[0] == 0, 'review creates no download jobs')
    status, duplicate = request(member, 'library-review', {'type': 'movie', 'id': 901}, token)
    check(status == 200 and duplicate['data']['id'] == review['data']['id'], 'repeat HTTP review deduplicates')
    status, _ = request(member, 'library-review', {'type': 'tv', 'id': 902}, token)
    check(status == 200, 'series can be reviewed independently')
    with sqlite3.connect(private / 'screenport.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM manager_alerts').fetchone()[0] == 4, 'exactly two queued notifications for each new review')
        db.execute("UPDATE manager_alerts SET status='failed',attempts=5,recipient='outdated@example.test'")
    status, _ = request(member, 'admin-email-retry', {}, token)
    check(status == 403, 'member cannot reroute retry emails')
    admin = client(); _, session = request(admin, 'session')
    _, login = request(admin, 'login', {'username': 'reviewadmin', 'password': 'FakeAdminPassword2026!'}, session['data']['csrf'])
    status, _ = request(admin, 'admin-email-retry', {}, login['data']['csrf'])
    check(status == 200, 'admin can retry failed review notifications')
    with sqlite3.connect(private / 'screenport.sqlite') as db:
        recipients = list(db.execute('SELECT kind,recipient,status,attempts FROM manager_alerts ORDER BY id'))
        check(all(email == ('member@example.test' if kind == 'library_review_user' else 'manager@example.test') and status == 'pending' and tries == 0 for kind, email, status, tries in recipients), 'retry preserves user audience and manager audience')
        check(db.execute('SELECT COUNT(*) FROM requests').fetchone()[0] == 0, 'full API review flow creates no downloads')
    status, _ = request(admin, 'admin-user-update', {'id': 2, 'role': 'user', 'status': 'disabled'}, login['data']['csrf'])
    check(status == 200, 'admin can disable review requester')
    status, _ = request(member, 'library-review', {'type': 'movie', 'id': 901}, token)
    check(status == 401, 'disabled requester cannot submit or read review result')
    print(f'PASS: {checks} real HTTP Jellyfin/review/access-control checks. Fake local integrations; no live email or downloads.')
finally:
    server.terminate()
    try: server.wait(timeout=5)
    except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
    backend.shutdown(); backend.server_close(); log.close()
