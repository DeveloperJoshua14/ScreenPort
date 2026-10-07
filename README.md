# ScreenPort

A private movie and TV request website for **PHP 8.2**, HTML, CSS, and JavaScript. It runs on CyberPanel/OpenLiteSpeed with a PHP command-line worker. A Docker deployment is included for Docker-based TrueNAS releases. No Node.js or Python is required on the server.

## What is included

- Username/password sign-in, account requests with admin approval, account creation, disabling users, password changes, and admin password resets.
- A responsive Discover screen with movie/TV search, posters, synopses, regional ratings, and recent releases from TMDB.
- “Not out Yet” across cards and details. Downloads are blocked on the server until availability is verified.
- qBittorrent search, a maximum of 15 movie or 30 TV candidates per model decision, and strict OpenAI Structured Outputs. One all-season pack is preferred; otherwise every fully aired season is searched separately.
- Filename checks for title, release year, resolution, and season coverage; CAM/screener rejection; no fabricated links, overlapping packs, single episodes, or partial-season plans.
- Movie/show categories and tags, including **Added by ScreenPort**, with the requested destinations.
- A durable SQLite job queue, interruption reconciliation, progress monitoring, per-user request visibility, and duplicate request prevention.
- Delayed user and manager email, with recipient deduplication and additional torrent information for the manager. SMTP uses verified TLS.
- Jellyfin lookup to label existing titles and avoid requesting a movie already in the library.
- Admin integration settings, encrypted credential overrides, connection checks, download limits, folder settings, account approvals, audit history, and failed job/email retries.

## Prerequisites

Use PHP **8.2** with `curl`, `pdo_sqlite`, `mbstring`, and `openssl`, plus Composer 2 to install the locked PHPMailer dependency. The latest PHP 8.2 security patch is recommended. `composer.lock` fixes the mail library version.

ScreenPort's web host/worker must reach qBittorrent and Jellyfin over a trusted network. A public CyberPanel server normally needs a private tunnel/VPN to your TrueNAS network. Keep their management interfaces private. qBittorrent needs enabled search plugins and the search runtime required by those plugins. ScreenPort uses qBittorrent's `/api/v2` Web UI API.

Configure TMDB, OpenAI API billing/access, and your SMTP provider. `OPENAI_MODEL` defaults to `gpt-4o-mini`; choose another Responses API model with Structured Outputs in admin settings if desired. The model receives title metadata and candidate names/sizes/peer counts. It does **not** receive credentials, user emails, server locations, or torrent URLs.

Quote `.env` values containing spaces, `#`, or `$` with single quotes, especially when using Docker Compose. Use a server address reachable from inside the container; `localhost` inside a container refers to that container.

## CyberPanel installation

1. Create the website in CyberPanel, select PHP 8.2, and enable HTTPS.
2. Put the project at a private application path such as `/home/screenport.example.com/screenport/`.
3. Set the website's **document root to `/home/screenport.example.com/screenport/public/`** in its virtual host configuration. CyberPanel exposes this under List Websites → your domain → vHost Conf. Update `docRoot`, then reload OpenLiteSpeed. Only `public/` should be served.
4. Copy your supplied `.env` into the application root. It must remain outside `public/`. The existing `qBittorrent_URL`, `qBittorrent_Username`, `qBittorrent_Password`, `JellyFin_URL`, `JellyFin_APIKEY`, and `OpenAI_APIKEY` names are supported alongside the uppercase names in `.env.example`.
5. Set `APP_ENV=production` and `APP_URL=https://your-screenport-domain` in `.env`. `APP_URL` must match the exact browser origin, with no path. Leave `STORAGE_PATH` blank to use the private `storage/` directory, or set a private absolute path.
6. Install dependencies and initialize the app using the PHP 8.2 executable. CyberPanel installations commonly provide `/usr/local/lsws/lsphp82/bin/php`; confirm the path on your server.

```sh
cd /home/screenport.example.com/screenport
composer install --no-dev --prefer-dist --no-interaction
/usr/local/lsws/lsphp82/bin/php bin/console.php init
/usr/local/lsws/lsphp82/bin/php bin/console.php create-admin
/usr/local/lsws/lsphp82/bin/php bin/console.php check
```

The admin command prompts for username, email, and a password of 12–72 bytes. On Linux, password entry is hidden. There is no default production password or web-based setup backdoor. The `init` command generates `APP_KEY` if missing; keep it with your backups. For automated provisioning, the admin command can read `SCREENPORT_ADMIN_USERNAME`, `SCREENPORT_ADMIN_EMAIL`, and `SCREENPORT_ADMIN_PASSWORD` from process environment variables. Unset those after use; don't pass passwords in command arguments.

7. Make the application readable by the website user. Make `storage/` writable only by the web/worker account; `.env` should have mode `0600`, and private storage directories `0700`. Run the worker under that same account, so its SQLite and session files have compatible ownership.
8. Run the background worker. A continuous worker gives the closest one-minute notification timing:

```sh
/usr/local/lsws/lsphp82/bin/php /home/screenport.example.com/screenport/bin/console.php worker
```

Customize `deploy/screenport-worker.service` with your actual user, path, and PHP executable, then install it as a system service. It restarts after failures and reboots. Alternatively, add a CyberPanel cron task under the website account:

```cron
* * * * * /usr/local/lsws/lsphp82/bin/php /home/screenport.example.com/screenport/bin/console.php worker --once >/dev/null 2>&1
```

Cron has one-minute granularity, so searches and notification timing will be slower than with the continuous worker. A process lock prevents simultaneous workers from adding duplicate torrents.

The worker reuses its qBittorrent login across polling and cron runs. Its session cookies live in private `storage/qbit/` (or the configured `STORAGE_PATH`) with owner-only permissions, never in the web directory. Connection checks use separate logins. If qBittorrent restarts or expires the session, ScreenPort authenticates again and restarts a missing search up to twice before requiring admin review.

9. Sign in with your admin account, open Administration, check connections, and confirm settings. Before exposing the website, verify that requests to `/.env`, `/storage/screenport.sqlite`, `/app/Config.php`, and `/composer.json` return 403 or 404. The root `.htaccess` is defense in depth; the `public/` document root is the primary protection.

## Docker and TrueNAS

The web and worker containers share one private SQLite volume. qBittorrent performs the actual filesystem writes, so **its** container must mount your media dataset at `/media`. ScreenPort doesn't need the media mount.

Set production configuration and a generated `APP_KEY` in `.env` before starting containers. You can run the local PHP `init` command first, or generate a 32-byte base64 key with your secret manager. The key must be identical for the web and worker containers. The sample environment values in `.env.example` must be replaced.

```sh
docker compose build
docker compose run --rm --user www-data web php bin/console.php create-admin
docker compose up -d
```

The base deployment binds port 8090 only to localhost and expects an existing HTTPS reverse proxy. Production API requests over HTTP are rejected. Forward HTTPS from your proxy and set `TRUSTED_PROXY_IPS` to the exact IP address that Apache sees for that proxy. Only those addresses can assert `X-Forwarded-Proto: https`. Do not use a wildcard or trust client-supplied proxy headers.

For an included HTTPS reverse proxy, set `SCREENPORT_DOMAIN` and the matching `APP_URL` in `.env`, arrange DNS, and run:

```sh
docker compose -f compose.yaml -f compose.https.yaml up -d --build
```

This adds Caddy with automatic TLS and a fixed trusted proxy address on a private network. Check that subnet `172.30.78.0/24` does not overlap your network; change it and the trusted address together if necessary. Ports 80/443 must be available and reachable for certificate issuance. On TrueNAS, an existing proxy is often easier because its management UI already uses those ports.

Docker-based TrueNAS releases support **Apps → Discover Apps → ⋮ → Install via YAML**. `deploy/truenas.yaml` is a Compose deployment template using absolute dataset paths. Build and load the image on the TrueNAS host first, or place your built image in a private registry and change `image:` accordingly. Create the private data dataset before installation and grant UID/GID 33 (`www-data`) access. Customize every `/mnt/tank/...` path, your network addresses, and your HTTPS proxy's trusted IP. Never use a world-writable dataset or mount app data into the web document root.

The entire application and worker are PHP. Hosting the worker on TrueNAS separately from a CyberPanel frontend is unnecessary. If you want all background processing on TrueNAS, deploy the complete containers there and reverse proxy the website from CyberPanel. SQLite must stay on local persistent storage, not a shared SMB/NFS mount.

## Release, selection, and path rules

Movie availability requires a past/current **digital, physical, or TV release** in `REGION` (US by default). A premiere or theatrical date is insufficient. Missing data stays unavailable. This is deliberately conservative: some older or obscure movies can remain blocked until TMDB's home release data is completed. The request and worker both recheck availability.

TV availability starts at the first broadcast/streaming date. Only numbered seasons whose listed episodes have all aired and have known dates are eligible. Specials and still-airing seasons are excluded. A show can have an available detail page before a full season is eligible; the worker will report that no complete season is available rather than download individual episodes.

Candidates are ranked by seeders after deterministic safety filtering and deduplication. Title, advertised audio, resolution, and exact season coverage are checked **before** the candidate cap, so unrelated shows, books, single-season packs in an all-series search, and full-series packs in a single-season search cannot consume the model's slots. Common ranges and lists (`S01-S05`, `Seasons 1 to 5`, `Season 1, 2, 3, 4 & 5`) are parsed on the server. The model sees at most 15 movie candidates or 30 TV candidates for each series/season decision. Movies prefer identity, language/no theater recordings, then sensible 1080p/4K quality, then approximately 1–4 GiB scaled by runtime. TV prefers identity, language/no theater recordings, 1080p with sensible 720p exceptions, then size scaled by episode count and runtime.

For English originals, English audio is required. **Assume original audio when no other audio is advertised** (`ASSUME_ORIGINAL_AUDIO=true`, enabled by default per the owner's preference) lets ordinary unlabeled releases use the title's original language from TMDB. Advertised foreign dubs, MULTI/DUAL audio without explicit original-language audio, and conflicting language labels are rejected by the server. Explicitly advertised original-language audio in a dual-language release is allowed. Subtitle labels do not prove audio language, and language words inside the media title are not treated as audio tags. For non-English originals, the same original-audio assumption applies and the model prefers English subtitles. Disable this setting in Administration → Settings → Catalog & selection to require an explicit original-language audio label. The assumption is recorded in search reports; it does not verify file contents. Uncertain identity or season completeness still fails for admin review. No torrent is auto-selected when the model is unavailable.

`TV_MAX_GIB_PER_HOUR` defaults to **2 GiB per hour of episodes**, using the season's episode count and average runtime. Oversized TV packs are excluded before model review, so a huge 1080p rip cannot defeat an otherwise sensible 720p pack solely on resolution. This configurable ceiling is separate from the overall `MAX_TORRENT_GB` limit; there is no minimum size that rejects efficient x265 encodes. The model receives total GiB, per-episode GiB, episode counts, and interpreted season/audio evidence rather than needing to infer every number from a large raw byte count.

Paths are generated on the server, never supplied by the browser or model:

| Selection | qBittorrent save path |
| --- | --- |
| Movie | `/media/Movies/<existing movie folder>/` |
| TV, all-season pack | `/media/TV Shows/` |
| TV, separate season packs | `/media/TV Shows/<sanitized show name>/` |
| Mature TV, all-season pack | `/media/Mature TV Shows/` |
| Mature TV, separate season packs | `/media/Mature TV Shows/<sanitized show name>/` |

Movie destinations default to these exact existing folders: **Action, Adults, Adventure, Divergent, Drama, Harry Potter, Horror, Hunger Games, James Bond, Kids, Marvel Cinematic Universe, Maze Runner, Murder Mysteries, Other, Pirates of the Caribbean, Romance, RomCom, Star Wars, Twilight, X-Men**. Keep `MOVIE_ROOT` set to the path **inside qBittorrent** (normally `/media/Movies`), even if the same storage is mapped to `M:\Movies` on Windows.

Routing uses an explicit movie override first, then recognized franchise collections, then **Adults** for NC-17 or TMDB adult-marked movies. R-rated action/horror stays in its genre folder. For other movies: G/PG Family or Animation → Kids; Romance + Comedy → RomCom; Romance → Romance; Horror → Horror; Mystery or Crime → Murder Mysteries; then Action, Adventure, or Drama; otherwise Other. All genres are considered. Franchise detection uses [TMDB movie details](https://developer.themoviedb.org/reference/movie-details), including collection identities and names; known standalone MCU and Star Wars movies have explicit identities. Older Deadpool/Wolverine films go to X-Men; Deadpool & Wolverine goes to Marvel Cinematic Universe. Unknown franchises fall back to genres instead of guessing a new folder.

**Administration → Settings → Folders & quality** includes the existing folder inventory (`MOVIE_EXISTING_FOLDERS`), per-movie overrides keyed by TMDB ID (`MOVIE_FOLDER_OVERRIDES`, e.g. `{"1032863":"RomCom"}`), and genre overrides (`MOVIE_GENRE_MAP`, e.g. `{"Science Fiction":"Adventure"}`). Per-movie overrides take priority over automatic routing. Genre overrides apply after franchise/Adults routing. **Allow custom new movie folders** defaults to disabled, so overrides pointing outside the inventory are ignored and automatic routing always uses an existing folder. Old `Sci-Fi`/other arbitrary genre overrides cannot create folders while this setting is disabled. Keep Other in the inventory as the fallback.

If a new folder is necessary, enable `ALLOW_NEW_MOVIE_FOLDERS` and set an explicit movie or genre override to the new folder name. Do not add it to the existing folder inventory until it is actually configured in your library. Once qBittorrent accepts a movie using that destination, ScreenPort queues a separate **download-manager-only email** with the movie, destination, and a reminder to check Jellyfin. This happens on the worker's next delivery pass, without waiting for the one-minute download update. Each destination gets one queued alert, persisted across worker restarts and retries; later movies in the same folder do not generate another alert. Reused torrents keep their actual save path and do not announce an unused requested destination. ScreenPort compares destinations with your configured inventory; it cannot inspect qBittorrent's remote filesystem or confirm that a directory was created. Jellyfin libraries already watching the parent Movies directory may only need a scan.

TV-MA, R, NC-17, and equivalent 18 ratings are mature. Unknown ratings also go to Mature TV Shows. Both TV rules are configurable. qBittorrent's automatic torrent management is disabled on adds so category rules cannot move downloads away from the requested destination.

Magnets are supported by default. ScreenPort rebuilds them from a validated info hash, retains verified public trackers, and removes unsafe/unreachable optional trackers and source URLs instead of discarding the torrent. A hash-only magnet relies on qBittorrent's DHT/peer exchange to find peers. Torrent-file links require explicitly allowed **public HTTPS** hosts in `TORRENT_ALLOWED_HOSTS`; keep the list restricted to trusted indexers. qBittorrent controls how these sites handle redirects and DNS, so don't allow arbitrary hosts. No uploaded URLs or user-supplied torrent links are accepted by the website.

Successful requests are shared across users; subsequent requests subscribe to the same download. An existing Jellyfin movie is blocked, while a series is labeled without claiming that every season is present. The current version does not monitor future TV seasons or delete library files. Admin retries keep the stored torrent plan and reconcile uncertain adds before resubmitting. If an add's outcome cannot be confirmed, it stops for admin inspection instead of risking a duplicate.

## Email and operations

Admins can open **Downloads → Search log** on any request. Each report shows the query, plugins, search result counts, candidate names/sizes/seeders, preliminary filter reasons, magnet cleanup/audio assumption notes, and the model's returned explanation and choices. New reports include a specific model reason for each candidate: selected, rejected, or an eligible alternative that was not preferred. The response schema follows the [official OpenAI Structured Outputs format](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses). If selected verdicts contradict the download selections, or candidate evaluations are incomplete, ScreenPort requests one corrected response using only the original safe metadata; two contradictory responses fail without adding torrents. Correction attempts are recorded. The server still validates identity, audio, resolution, and exact season coverage independently. A report distinguishes results filtered before the model from an empty model decision or a validation error. These are returned explanations, not hidden model reasoning. Reports start with their respective updates; older searches cannot be reconstructed. Retry a failed request to capture a fresh report.

Reports live in the private SQLite database, are accessible only to approved admins, and exclude torrent URLs, credentials, and recipient emails. Untrusted candidate/model text is redacted before storage and escaped in the interface. Keep at most ten reports per request, 500 overall, and 30 days (pruned when a new search starts). Three acquisition failures require admin retry; successful intermediate polls do not reset this failure count indefinitely.

Email is scheduled approximately 60 seconds after all selected torrents have been submitted. It includes media title, rating, release dates, synopsis, cover image, progress, and the current ETA. Unknown ETA is explicitly labeled. The manager receives size, speed, seeders, peers, torrent state, and destination. If the manager is also a requesting user, they receive one manager email. User recipients are sent individually.

`SMTP_ENCRYPTION=tls` means STARTTLS, normally on port 587; `ssl` means implicit TLS, normally on 465. Certificate validation stays enabled. Connection checks authenticate but don't send email. Failed deliveries, including new-folder alerts, retry with backoff up to five times and can be retried from Administration → Activity. SMTP has no exactly-once delivery guarantee: a server disconnect after accepting a message can produce a duplicate on retry.

Back up `.env` and the private storage dataset together. `APP_KEY` encrypts saved secret overrides; losing it makes those credentials unreadable. Stop web writes and the worker for a filesystem backup, or use SQLite's backup API. Never distribute SQLite/session backups with a public release.

Use `php bin/console.php reset-password USERNAME` to recover access. Password changes, role changes, and account disabling revoke existing sessions. Sign-in sessions expire after 30 minutes of inactivity or 12 hours total. Login attempts, registrations, catalog usage, requests, and connection tests are rate limited. Admin actions and worker failures are recorded without credential values. Normal users see only the requests they follow.

## Local development and verification

```sh
composer install
php tests/run.php
```

The behavior suite intercepts all external HTTP requests and mail delivery. It verifies release gates, path generation, encrypted settings, candidate constraints, duplicate prevention, movie and TV worker workflows, and separate user/manager email content. Run `php tests/wire_selection.php` for the reported Wire filenames, season parser, language policy, candidate cap, TV size limits, and bounded response correction regressions. These suites do not send live torrents or email, and do not charge the OpenAI account.

With Python available for development, `python tests/qbit_sessions.py /path/to/php` also tests real cURL cookie persistence across separate PHP processes against a localhost stub, including expired sessions and credential changes. Python is not needed to run ScreenPort itself.

For a private development preview, set `APP_ENV=local`, `APP_URL=http://127.0.0.1:8098`, and a separate `STORAGE_PATH` in your shell, create a local account, then run:

```sh
php -S 127.0.0.1:8098 -t public public/router.php
```

Set `DOWNLOADS_ENABLED=false` when testing the interface against real integration credentials. `DEMO_MODE=true` with `APP_ENV=local` provides a clearly labeled sample catalog and blocks downloads/email. Neither mode should be used on an internet-facing server. The PHP development server is for local preview only.

Official integration references: [qBittorrent API](https://github.com/qbittorrent/qBittorrent/wiki/WebUI-API-(qBittorrent-5.0)), [TMDB release types](https://developer.themoviedb.org/docs/region-support), [OpenAI Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=responses), [PHPMailer](https://github.com/PHPMailer/PHPMailer), [TrueNAS custom apps](https://apps.truenas.com/managing-apps/installing-custom-apps/).
