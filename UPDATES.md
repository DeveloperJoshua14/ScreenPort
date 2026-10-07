# ScreenPort updates through October 7, 2026

## Completed before manager notifications

1. **PHP application and deployment:** ScreenPort runs on PHP 8.2 with HTML, CSS, and JavaScript. CyberPanel setup, a persistent worker/cron option, Docker/TrueNAS deployment files, and deployment packages are included.
2. **Accounts and administration:** Username/password sign-in, pending account approval, admin-created accounts, roles, account disabling, password changes/reset, integration settings, connection checks, and activity history.
3. **Credential and access protection:** Private `.env`/SQLite/session storage, encrypted saved credential overrides, approved-user/admin checks, CSRF/origin protection, rate limiting, secure session handling, and positive-allowlist deployment packages that exclude secrets/private state. `.gitignore` excludes credentials, runtime data, local tools, and archives.
4. **Media catalog and release gates:** TMDB movie/TV search, new-release browsing, artwork, descriptions, ratings, release dates, and “Not out Yet” restrictions until home release or broadcast is confirmed. TV acquisition uses fully aired seasons.
5. **Download automation:** qBittorrent search, capped model review (15 movie/30 TV candidates), title/audio/quality/season validation, all-season preference with individual-season fallback, movie/TV categories, “Added by ScreenPort” tags, destination paths, durable plans, and duplicate/uncertain-add reconciliation.
6. **Library and progress notifications:** Jellyfin movie duplicate checks, separate user/download-manager progress emails approximately one minute after submission, recipient deduplication, ETA/progress/media information, extra manager torrent details, and failed-email retries.
7. **Search fixes and diagnostics:** Persistent qBittorrent search sessions to fix session-scoped 404 results, bounded restart/retry handling, removal of unsafe optional magnet trackers while retaining valid hashes, private admin search reports, filter explanations, and candidate-specific model verdicts. Reports redact credentials/links and escape untrusted text.
8. **Movie folder rules:** The owner's 20 movie folders, franchise-before-genre routing, Adults for NC-17/adult-marked movies, RomCom/Kids/etc. rules, Other fallback, configurable overrides, and a download-manager alert when an explicitly permitted new movie destination is used.
9. **General selection improvements:** Filter unrelated titles and incorrect season packs before candidate limits; parse common season lists/ranges; assume original audio when no conflicting audio is advertised (owner-selected policy); reject advertised conflicting dubs; calculate episode/runtime size budgets and GiB evidence; allow sensible 720p TV exceptions; require consistent model selections/evaluations with one bounded correction. These are general rules, not show-specific exceptions.
10. **Verification:** Isolated simulated workflow/email tests, selection regressions, localhost qBittorrent session checks, HTTP access/security checks, and diagnostics rendering/escaping checks. Selection-only live model checks were also performed without submitting torrents or sending email.

## Added in this update

- **Account-request emails:** New valid registrations awaiting approval notify `ACCOUNT_MANAGER_EMAIL`, falling back to `DOWNLOAD_MANAGER_EMAIL` when blank. Messages include the requesting username/email and review instructions, with no passwords. Invalid/duplicate submissions and approved accounts created by an admin do not generate approval alerts.
- **Download-start failure emails:** Authenticated submissions that cannot be queued notify the download manager. Background failures send an initial retrying alert and a final review-required alert if retries are exhausted. Duplicate alerts are suppressed; an admin retry begins a new notification cycle.
- **Accepted-but-not-started downloads:** Missing torrents, qBittorrent file/disk errors, status-check failures, and no confirmed progress after a configurable timeout (10 minutes by default) notify the download manager. Monitoring alerts do not stop or resubmit torrents.
- **Reliable delivery:** Account/failure alerts are persisted privately, delivered by the worker, retried up to five times after SMTP failures, and included in the admin failed-email count/retry control. Alerts require a running worker and working SMTP; events before this update are not backfilled.

All update ZIPs exclude the real `.env` and private storage. Installing an update does not require replacing those files. `ACCOUNT_MANAGER_EMAIL` is optional; its blank default uses the existing download manager address.

## Jellyfin availability and manual review

- Movies and shows detected on Jellyfin have a green card/detail treatment and “On Jellyfin!” label, including homepage and search results.
- Detected titles offer **Open in Jellyfin** and **Not on Jellyfin? Request review** instead of an automatic download request. TV matches still explain that seasons or episodes may be missing.
- An optional `JELLYFIN_PUBLIC_URL` supplies the browser-reachable Jellyfin server base address; links contain only item/server IDs, never API credentials.
- Manual review freshly verifies the library match and queues a download-manager email with media/Jellyfin information and the requester's username, email, account ID, and role. The requester gets an email confirming that review was requested.
- Reviews use private durable storage and email retries, suppress repeated clicks for 24 hours, and create no download jobs. The download API blocks detected movies and shows from bypassing the review flow.
- New simulated integration, renderer, and real localhost HTTP tests cover library links, both email audiences, escaped media text, authenticated requester identity, duplicate suppression, and recipient-preserving email retry.

## Admin download controls

- Admin-only **Suspend**, **Resume**, and **Remove request** buttons on Downloads, including failed acquisition requests.
- Suspension holds background jobs/retries and request emails, stops unshared linked torrents, and remains in effect across worker restarts. Resume preserves prior external pauses; failed requests still need Retry.
- Removal deletes linked unshared torrent entries while keeping downloaded files, cancels jobs/emails, hides the request, and preserves a record preventing members from restarting it. Shared or untagged external torrents are preserved.
- **Show removed requests** and **Restore request** let admins review removed history and explicitly restore a request for later Retry.
- Durable queued commands and worker checkpoints handle in-flight adds, service failures, and superseding commands. Pending failures remain blocked and retry with backoff. qBittorrent 4.x and 5.x controls are supported.
- Simulated worker/integration tests, real isolated HTTP access-control tests, and UI tests verify admin access, confirmation, request lifecycle, search cleanup, retry behavior, and file preservation.
