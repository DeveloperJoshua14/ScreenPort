<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https://image.tmdb.org data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') header('Strict-Transport-Security: max-age=31536000');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="ScreenPort — your private movie and TV request library.">
  <title>ScreenPort</title>
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
  <link rel="stylesheet" href="/assets/app.css">
  <script src="/assets/app.js" defer></script>
</head>
<body>
  <div id="app"><div class="boot"><span class="brand-mark">S</span><p>Opening ScreenPort…</p></div></div>
  <div id="toast" role="status" aria-live="polite"></div>
  <dialog id="media-dialog" aria-label="Media details"><div id="media-detail"></div></dialog>
  <dialog id="account-dialog" aria-label="Account settings"><div id="account-detail"></div></dialog>
</body>
</html>
