"""Real API and worker control test, isolated from live keys, SMTP and services."""
import base64, http.cookiejar, json, os, socket, sqlite3, subprocess, time, urllib.error, urllib.request, uuid
from pathlib import Path
root = Path(__file__).resolve().parents[1]
php = root / '.tools/php/php.exe'
private = root / '.test-output' / ('control-http-' + uuid.uuid4().hex[:8]); private.mkdir()
with socket.socket() as probe:
    probe.bind(('127.0.0.1', 0)); port = probe.getsockname()[1]
base = f'http://127.0.0.1:{port}'
env = os.environ.copy()
env.update({'SCREENPORT_ENV_FILE': str(private / '.env'), 'APP_ENV': 'local', 'APP_URL': base, 'APP_KEY': base64.b64encode(os.urandom(32)).decode(), 'STORAGE_PATH': str(private)})
(private / '.env').write_text('DOWNLOADS_ENABLED=false\nDOWNLOAD_MANAGER_EMAIL=manager@example.test\n', encoding='utf-8')
subprocess.run([str(php), str(root / 'tests/download_control_fixture.php')], env=env, cwd=root, check=True, stdout=subprocess.DEVNULL)
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
def login(name, password):
    opener = client(); _, session = request(opener, 'session')
    status, result = request(opener, 'login', {'username': name, 'password': password}, session['data']['csrf'])
    assert status == 200
    return opener, result['data']['csrf']
def tick():
    result = subprocess.run([str(php), 'bin/console.php', 'worker', '--once'], env=env, cwd=root, check=True, capture_output=True, text=True)
    check('could not complete' not in result.stdout, 'isolated worker successfully applies controls')
try:
    guest = client()
    for _ in range(60):
        try: _, session = request(guest, 'session'); break
        except urllib.error.URLError: time.sleep(.05)
    else: raise RuntimeError('Test server failed to start')
    status, _ = request(guest, 'admin-download-control', {'id': 1, 'command': 'remove'}, session['data']['csrf'])
    check(status == 401, 'guest cannot control requests')
    status, _ = request(guest, 'admin-download-control')
    check(status == 405, 'GET cannot mutate requests')
    member, mt = login('controlmember', 'FakeMemberPassword2026!')
    admin, at = login('controladmin', 'FakeAdminPassword2026!')
    for command in ['suspend', 'resume', 'remove', 'restore']:
        status, _ = request(member, 'admin-download-control', {'id': 1, 'command': command}, mt)
        check(status == 403, f'member cannot {command} any request')
    status, _ = request(member, 'downloads&include_removed=1')
    check(status == 403, 'removed history is admin only')
    status, _ = request(admin, 'admin-download-control', {'id': 1, 'command': 'remove'})
    check(status == 403, 'admin control requires CSRF')
    status, _ = request(admin, 'admin-download-control', {'id': 1, 'command': 'remove'}, at, 'https://attacker.invalid')
    check(status == 403, 'foreign origin cannot control admin session')
    status, _ = request(admin, 'admin-download-control', {'id': 999, 'command': 'remove'}, at)
    check(status == 404, 'invalid request ID rejected')
    status, _ = request(admin, 'admin-download-control', {'id': 1, 'command': 'delete-files'}, at)
    check(status == 422, 'file deletion command not supported')
    status, _ = request(admin, 'admin-download-control', {'id': 2, 'command': 'suspend'}, at)
    check(status == 200, 'admin suspension accepted')
    tick()
    _, downloads = request(admin, 'downloads')
    check(next(r for r in downloads['data']['requests'] if r['id'] == 2)['status'] == 'suspended', 'worker applies suspended state')
    status, result = request(member, 'request', {'type': 'movie', 'id': 902}, mt)
    check(status == 409 and 'suspended' in result['error'], 'user cannot restart suspended media')
    status, _ = request(admin, 'admin-download-control', {'id': 2, 'command': 'resume'}, at)
    check(status == 200, 'admin resume accepted')
    tick()
    _, downloads = request(admin, 'downloads')
    check(next(r for r in downloads['data']['requests'] if r['id'] == 2)['status'] == 'queued', 'resume restores queue without starting paused global downloads')
    status, _ = request(admin, 'admin-download-control', {'id': 1, 'command': 'remove', 'deleteFiles': True}, at)
    check(status == 200, 'failed request removal accepted independently of download settings')
    tick()
    _, downloads = request(admin, 'downloads')
    check(all(r['id'] != 1 for r in downloads['data']['requests']), 'removed request hidden from normal admin list')
    _, downloads = request(member, 'downloads')
    check(all(r['id'] != 1 for r in downloads['data']['requests']), 'removed request hidden from subscribed user list')
    _, removed = request(admin, 'downloads&include_removed=1')
    check(next(r for r in removed['data']['requests'] if r['id'] == 1)['status'] == 'removed', 'admin can view removed requests')
    status, _ = request(member, 'request', {'type': 'movie', 'id': 901}, mt)
    check(status == 409, 'removed media cannot be recreated by member')
    status, _ = request(admin, 'admin-retry', {'id': 1}, at)
    check(status == 409, 'retry cannot bypass removed-state hold')
    status, _ = request(admin, 'admin-download-control', {'id': 1, 'command': 'restore'}, at)
    check(status == 200, 'admin can restore removed record')
    _, downloads = request(admin, 'downloads')
    check(next(r for r in downloads['data']['requests'] if r['id'] == 1)['status'] == 'failed', 'restoration requires explicit retry rather than auto download')
    with sqlite3.connect(private / 'screenport.sqlite') as db:
        check(db.execute('SELECT COUNT(*) FROM manager_alerts').fetchone()[0] == 0, 'deliberate holds do not generate failure-email alerts')
        check(db.execute('SELECT COUNT(*) FROM torrents').fetchone()[0] == 0, 'control workflow never initiates torrent download')
    print(f'PASS: {checks} real HTTP admin download-control checks. No live service, email or torrent mutations.')
finally:
    server.terminate()
    try: server.wait(timeout=5)
    except subprocess.TimeoutExpired: server.kill(); server.wait(timeout=5)
    log.close()
