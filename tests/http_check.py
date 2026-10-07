"""Check the running isolated preview over HTTP; no external mutations."""
import json
import urllib.error
import urllib.request
import http.cookiejar
import uuid

BASE = "http://127.0.0.1:8098"
checks = 0

def check(condition, label):
    global checks
    checks += 1
    if not condition:
        raise AssertionError(label)

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def request(opener, action, body=None, token=None, origin=BASE):
    headers = {}
    if body is not None:
        headers = {"Content-Type": "application/json", "Origin": origin}
        if token:
            headers["X-CSRF-Token"] = token
    req = urllib.request.Request(BASE + "/api.php?action=" + action,
        data=json.dumps(body).encode() if body is not None else None, headers=headers)
    try:
        with opener.open(req, timeout=120) as response:
            return response.status, json.load(response), response.headers
    except urllib.error.HTTPError as error:
        return error.code, json.load(error), error.headers

c = client()
status, result, headers = request(c, "session")
check(status == 200 and result["data"]["user"] is None, "guest session")
csrf = result["data"]["csrf"]
signup_name = "pending-" + uuid.uuid4().hex[:8]
status, registration, _ = request(c, "register", {"username": signup_name, "email": signup_name + "@screenport.invalid", "password": "PendingSignupPassword2026!"}, csrf)
check(status == 200 and "approve" in registration["data"]["message"], "public account request creates pending account")
signup_client = client()
_, signup_session, _ = request(signup_client, "session")
status, _, _ = request(signup_client, "login", {"username": signup_name, "password": "PendingSignupPassword2026!"}, signup_session["data"]["csrf"])
check(status == 403, "emailed account request still requires approval")
check("no-store" in headers["Cache-Control"], "private API cache disabled")
check("HttpOnly" in headers["Set-Cookie"] and "SameSite=Strict" in headers["Set-Cookie"], "protected cookie")
status, _, _ = request(c, "downloads")
check(status == 401, "downloads require authentication")
status, _, _ = request(c, "admin")
check(status == 401, "admin requires authentication")
status, _, _ = request(c, "admin-search-log&id=1")
check(status == 401, "search logs require authentication")
status, _, _ = request(c, "login", {"username": "preview", "password": "LocalPreview!Only2026"})
check(status == 403, "login CSRF is required")
status, _, _ = request(c, "login", {"username": "preview", "password": "LocalPreview!Only2026"}, csrf, "https://attacker.invalid")
check(status == 403, "foreign origin rejected")
status, result, _ = request(c, "login", {"username": "preview", "password": "LocalPreview!Only2026"}, csrf)
check(status == 200 and result["data"]["user"]["role"] == "admin", "valid admin login")
csrf = result["data"]["csrf"]
status, result, _ = request(c, "admin")
check(status == 200, "admin settings accessible")
check(all(f["value"] == "" for f in result["data"]["settings"] if f["secret"]), "API returns no credential values")
fields = {f["key"]: f for f in result["data"]["settings"]}
check(len(fields["MOVIE_EXISTING_FOLDERS"]["value"].split(",")) == 20, "all existing movie folders available to admin")
check(fields["ALLOW_NEW_MOVIE_FOLDERS"]["value"] == "false", "new movie folders disabled by default")
check("MOVIE_FOLDER_OVERRIDES" in fields, "per-movie folder controls available")
check(fields["ACCOUNT_MANAGER_EMAIL"]["value"] == "", "account manager defaults through blank fallback setting")
check(fields["DOWNLOAD_START_TIMEOUT_MINUTES"]["value"] == "10", "no-progress manager alert defaults to ten minutes")
check(any(u["username"] == signup_name and u["status"] == "pending" for u in result["data"]["users"]), "admin can review requested account")
status, _, _ = request(c, "admin-settings", {"settings": {"ACCOUNT_MANAGER_EMAIL": "accounts@screenport.invalid"}}, csrf)
check(status == 200, "admin can configure account manager separately")
status, _, _ = request(c, "admin-settings", {"settings": {"ACCOUNT_MANAGER_EMAIL": "invalid email"}}, csrf)
check(status == 422, "invalid account manager rejected")
status, _, _ = request(c, "admin-settings", {"settings": {"ACCOUNT_MANAGER_EMAIL": ""}}, csrf)
check(status == 200, "admin can restore download-manager fallback")
check(fields["ASSUME_ORIGINAL_AUDIO"]["value"] == "true", "approved original-audio assumption enabled by default")
check(fields["TV_MAX_GIB_PER_HOUR"]["value"] == "2", "TV size budget exposed to admin")
status, _, _ = request(c, "admin-settings", {"settings": {"TV_MAX_GIB_PER_HOUR": "0"}}, csrf)
check(status == 422, "invalid TV runtime size budget rejected")
status, _, _ = request(c, "admin-settings", {"settings": {"ASSUME_ORIGINAL_AUDIO": "sometimes"}}, csrf)
check(status == 422, "invalid audio policy switch rejected")
status, _, _ = request(c, "admin-settings", {"settings": {"MOVIE_FOLDER_OVERRIDES": '{"550":"Romance"}'}}, csrf)
check(status == 200, "admin can save movie folder override")
status, _, _ = request(c, "admin-settings", {"settings": {"MOVIE_FOLDER_OVERRIDES": '{"550":"../private"}'}}, csrf)
check(status == 422, "movie folder traversal rejected over HTTP")
if result["data"]["email_failures"]:
    status, _, _ = request(c, "admin-email-retry", {}, csrf)
    check(status == 200, "admin can retry failed emails including folder alerts")
    _, updated, _ = request(c, "admin")
    check(updated["data"]["email_failures"] == 0, "folder alert failures cleared for retry")
status, result, _ = request(c, "admin-search-log&id=0")
check(status == 404, "admin log endpoint validates request existence")
status, data, _ = request(c, "downloads")
if data["data"]["requests"]:
    report_id = data["data"]["requests"][0]["id"]
    status, report, log_headers = request(c, "admin-search-log&id=" + str(report_id))
    check(status == 200 and "logs" in report["data"], "admin can read request search reports")
    check("no-store" in log_headers["Cache-Control"], "search reports are never publicly cached")
    encoded = json.dumps(report["data"])
    check("fileUrl" not in encoded and "magnet:?" not in encoded and "http://" not in encoded and "https://" not in encoded, "search reports contain no download or backend URLs")
member_name = "testmember-" + uuid.uuid4().hex[:8]
status, result, _ = request(c, "admin-user-create", {"username": member_name, "email": member_name + "@screenport.invalid", "password": "TestMemberPassword2026!", "role": "user"}, csrf)
check(status == 200, "admin creates approved member")
status, result, _ = request(c, "admin")
member = next(u for u in result["data"]["users"] if u["username"] == member_name)
member_client = client()
_, member_session, _ = request(member_client, "session")
status, result, _ = request(member_client, "login", {"username": member_name, "password": "TestMemberPassword2026!"}, member_session["data"]["csrf"])
check(status == 200, "member can sign in")
member_token = result["data"]["csrf"]
status, _, _ = request(member_client, "admin")
check(status == 403, "member cannot read admin settings")
status, _, _ = request(member_client, "admin-search-log&id=1")
check(status == 403, "member cannot read search logs")
status, _, _ = request(member_client, "admin-settings", {"settings": {"DOWNLOADS_ENABLED": "true"}}, member_token)
check(status == 403, "member cannot change integration settings")
status, _, _ = request(member_client, "admin-settings", {"settings": {"ALLOW_NEW_MOVIE_FOLDERS": "true"}}, member_token)
check(status == 403, "member cannot enable new movie destinations")
status, _, _ = request(member_client, "admin-email-retry", {}, member_token)
check(status == 403, "member cannot retry manager folder alerts")
status, _, _ = request(member_client, "admin-settings", {"settings": {"ACCOUNT_MANAGER_EMAIL": "attacker@screenport.invalid"}}, member_token)
check(status == 403, "member cannot redirect account manager notifications")
status, result, _ = request(member_client, "downloads")
check(status == 200 and result["data"]["requests"] == [], "member sees only subscribed requests")
status, _, _ = request(c, "admin-user-update", {"id": member["id"], "role": "user", "status": "disabled"}, csrf)
check(status == 200, "admin disables member")
status, _, _ = request(member_client, "downloads")
check(status == 401, "disabled member session is revoked immediately")
status, result, _ = request(c, "request", {"type": "movie", "id": 550}, csrf)
check(status == 409 and "paused" in result["error"], "preview downloads cannot mutate real server")
for path in ["/.env", "/app/Config.php", "/storage/screenport.sqlite", "/composer.json", "/../.env", "/%2e%2e/.env"]:
    try:
        c.open(BASE + path)
        check(False, "private file protection: " + path)
    except urllib.error.HTTPError as e:
        check(e.code in [403, 404], "private file protection: " + path)
status, result, _ = request(c, "logout", {}, csrf)
check(status == 200, "empty object mutation body accepted")
status, _, _ = request(c, "admin")
check(status == 401, "logout revokes access")
print(f"PASS: {checks} HTTP security checks. No downloads or email sent.")
