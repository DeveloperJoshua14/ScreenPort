const app = document.querySelector('#app');
const dialog = document.querySelector('#media-dialog');
const accountDialog = document.querySelector('#account-dialog');
const searchLogDialog = document.querySelector('#search-log-dialog');
const state = { user: null, csrf: '', view: 'discover', type: 'movie', query: '', page: 1, totalPages: 1, media: [], downloads: [], adminTab: 'accounts', demo: false, catalogReady: false, busy: false };
let catalogVersion = 0, downloadTimer, toastTimer;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const icon = (name, size = 20) => {
  const paths = {
    discover: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    download: '<path d="M12 3v12m-5-5 5 5 5-5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
    shield: '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
    search: '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
    movie: '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="M3 10h18M7 5l3 5m4-5 3 5"/>',
    tv: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="m8 3 4 4 4-4m-7 14h6"/>',
    arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    logout: '<path d="M9 4H4v16h5m6-12 4 4-4 4m-7-4h11"/>',
    star: '<path d="m12 3 3 6 6 1-4.5 4.5L18 21l-6-3-6 3 1.5-6.5L3 10l6-1 3-6Z"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
    chevron: '<path d="m9 5 7 7-7 7"/>',
  };
  return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.movie}</svg>`;
};
function toast(message, error = false) {
  const node = document.querySelector('#toast'); node.textContent = message; node.className = `visible${error ? ' error' : ''}`;
  clearTimeout(toastTimer); toastTimer = setTimeout(() => node.className = '', 6500);
}
async function api(action, body, params = {}, signal) {
  const query = new URLSearchParams({ action, ...params });
  const response = await fetch(`/api.php?${query}`, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', signal,
    headers: body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf }, body: body === undefined ? undefined : JSON.stringify(body) });
  let result;
  try { result = await response.json(); } catch { throw new Error('ScreenPort is unavailable. Check the server setup.'); }
  if (!result.ok) {
    if (response.status === 401 && state.user && action !== 'profile') { state.user = null; clearInterval(downloadTimer); await boot(); }
    throw new Error(result.error || 'Something went wrong. Please try again.');
  }
  if (result.data.csrf) state.csrf = result.data.csrf;
  return result.data;
}
const brand = () => `<span class="brand-mark"><svg width="26" height="26" viewBox="0 0 64 64" aria-hidden="true"><path d="M43 18H27a10 10 0 0 0 0 20h10a4 4 0 1 1 0 8H21" stroke="currentColor" fill="none" stroke-width="8" stroke-linecap="round"/></svg></span><span>ScreenPort<span class="brand-dot">.</span></span>`;
async function boot() {
  try {
    const data = await api('session'); Object.assign(state, { user: data.user, demo: data.demo, catalogReady: data.catalog_ready });
    if (data.user) { shell(); navigate('discover'); }
    else renderAuth(false, data.registration_open, data.setup_required);
  } catch (error) {
    app.innerHTML = `<main class="setup-screen"><a class="brand" href="/">${brand()}</a><div class="setup-card">${icon('shield', 34)}<h1>Let’s connect your screen.</h1><p>ScreenPort needs its server configuration and first admin account before opening.</p><p class="muted">${esc(error.message)}</p><p class="muted">Follow the setup instructions included with the project, then reload this page.</p><button class="button primary" id="reload">Reload ScreenPort ${icon('arrow')}</button></div></main>`;
    document.querySelector('#reload').addEventListener('click', boot);
  }
}
function renderAuth(register, registrationOpen = true, setupRequired = false) {
  app.innerHTML = `<main class="auth-layout">
    <section class="auth-story"><a class="brand" href="/">${brand()}</a><div class="auth-orbit"><span class="orbit-label">YOUR PRIVATE SCREENING ROOM</span><div class="orbit-disc"><span class="orbit-play">▷</span></div><span class="orbit-caption">A good night starts with a great story.</span></div>
    <div class="auth-copy"><span class="eyebrow">LESS SEARCHING. MORE WATCHING.</span><h1>Your next watch.<br><em>Already on its way.</em></h1><p>Find a movie. Discover a show. Let ScreenPort bring it to your library.</p></div><p class="auth-foot">A private library, curated by you.</p></section>
    <section class="auth-panel"><span class="private-pill">${icon('shield', 14)} PRIVATE ACCESS</span><div class="auth-form-wrap"><h2>${register ? 'Make yourself at home.' : 'Welcome back.'}</h2><p class="muted">${register ? 'Request an account. Your admin will let you in.' : 'Sign in to find your next favorite.'}</p>
    ${setupRequired ? '<div class="notice">The first admin account must be created using the server setup command.</div>' : ''}
    ${state.demo ? '<div class="notice">Design preview · real downloads and email are disabled.</div>' : ''}
    <form id="auth-form"><label>Username<input name="username" autocomplete="username" minlength="3" maxlength="40" placeholder="Your username" required></label>
    ${register ? '<label>Email address<input name="email" type="email" autocomplete="email" placeholder="you@example.com" required></label>' : ''}
    <label>Password<input name="password" type="password" autocomplete="${register ? 'new-password' : 'current-password'}" minlength="${register ? 12 : 1}" maxlength="72" placeholder="${register ? 'At least 12 characters' : 'Your password'}" required></label>
    <p id="auth-error" class="form-error" role="alert"></p><button class="button primary full" type="submit">${register ? 'Request access' : 'Sign in'} ${icon('arrow')}</button></form>
    ${registrationOpen && !setupRequired ? `<p class="auth-switch">${register ? 'Already have an account?' : 'New to ScreenPort?'} <button class="text-button" id="switch-auth">${register ? 'Sign in' : 'Request access'}</button></p>` : ''}
    <p class="security-note">${icon('shield', 14)} Your library is only a password away.</p></div></section></main>`;
  document.querySelector('#switch-auth')?.addEventListener('click', () => renderAuth(!register, registrationOpen, setupRequired));
  document.querySelector('#auth-form').addEventListener('submit', async event => {
    event.preventDefault(); const button = event.currentTarget.querySelector('button[type=submit]'); const data = Object.fromEntries(new FormData(event.currentTarget)); button.disabled = true;
    try {
      const result = await api(register ? 'register' : 'login', data);
      if (register) { renderAuth(false, registrationOpen); toast(result.message); }
      else { state.user = result.user; shell(); navigate('discover'); }
    } catch (error) { document.querySelector('#auth-error').textContent = error.message; button.disabled = false; }
  });
}
function shell() {
  clearInterval(downloadTimer);
  app.innerHTML = `<div class="workspace"><aside class="sidebar"><a class="brand" href="/">${brand()}</a><div class="nav-caption">YOUR LIBRARY</div><nav aria-label="Main navigation">
  <button class="nav-item" data-view="discover" aria-label="Discover">${icon('discover')}<span>Discover</span></button><button class="nav-item" data-view="downloads" aria-label="Downloads">${icon('download')}<span>Downloads</span><span class="nav-count" id="download-count" hidden></span></button>
  ${state.user.role === 'admin' ? `<div class="nav-caption admin-caption">MANAGE</div><button class="nav-item" data-view="admin" aria-label="Administration">${icon('shield')}<span>Administration</span></button>` : ''}</nav>
  <div class="sidebar-bottom"><div class="library-note"><span class="live-dot"></span><span>Your next story starts here.</span><p>Made for movie nights.<br>And “just one more episode.”</p></div><button class="profile-button" id="profile-button"><span class="avatar">${esc(state.user.username.slice(0, 1).toUpperCase())}</span><span><strong>${esc(state.user.username)}</strong><small>${state.user.role === 'admin' ? 'Library admin' : 'Library member'}</small></span>${icon('chevron', 16)}</button></div></aside>
  <main class="main"><header class="topbar"><div class="breadcrumb">Your library <span>/</span> <strong id="breadcrumb">Discover</strong></div><form class="search-form" id="search-form" role="search">${icon('search')}<input id="search-input" aria-label="Search movies and TV shows" placeholder="Find a movie or TV show…" autocomplete="off" maxlength="120"><kbd>↵</kbd></form><span class="private-pill">${icon('shield', 13)} PRIVATE LIBRARY</span><button class="mobile-profile avatar" id="mobile-profile" aria-label="Your account">${esc(state.user.username.slice(0, 1).toUpperCase())}</button></header>
  ${state.demo ? '<div class="demo-banner">Design preview · sample catalog · downloads and email disabled</div>' : ''}<div class="page" id="page"></div><footer class="page-footer"><span>ScreenPort <span class="brand-dot">.</span> Bring the story home.</span><span>Metadata and artwork by <a href="https://www.themoviedb.org/" target="_blank" rel="noopener noreferrer">TMDB</a>. This product uses the TMDB API but is not endorsed or certified by TMDB.</span></footer></main></div>`;
  document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click', () => { state.query = ''; state.page = 1; document.querySelector('#search-input').value = ''; navigate(button.dataset.view); }));
  document.querySelector('#search-form').addEventListener('submit', event => { event.preventDefault(); state.query = document.querySelector('#search-input').value.trim(); if (state.query) state.type = 'all'; state.page = 1; navigate('discover'); });
  document.querySelector('#profile-button').addEventListener('click', profile);
  document.querySelector('#mobile-profile').addEventListener('click', profile);
}
function navigate(view) {
  state.view = view; clearInterval(downloadTimer); catalogVersion++;
  window.scrollTo({ top: 0, behavior: 'instant' });
  document.querySelectorAll('[data-view]').forEach(button => { button.classList.toggle('active', button.dataset.view === view); button.setAttribute('aria-current', button.dataset.view === view ? 'page' : 'false'); });
  document.querySelector('#breadcrumb').textContent = view === 'admin' ? 'Administration' : view === 'downloads' ? 'Downloads' : 'Discover';
  if (view === 'discover') renderDiscover();
  if (view === 'downloads') { renderDownloads(); downloadTimer = setInterval(() => loadDownloads(true), 15000); }
  if (view === 'admin') renderAdmin();
}
const skeleton = () => `<div class="poster-grid skeleton-grid" aria-label="Loading titles">${Array.from({ length: 6 }, () => '<div class="skeleton-card"><div></div><span></span><small></small></div>').join('')}</div>`;
async function renderDiscover() {
  const page = document.querySelector('#page');
  page.innerHTML = `<div class="page-heading"><div><span class="eyebrow">THE NEXT THING YOU’LL LOVE</span><h1>${state.query ? 'Find your next story.' : 'Good stories. One place.'}</h1><p>${state.query ? `Searching movies and TV for “${esc(state.query)}”` : 'Fresh releases, familiar favorites, and your next obsession.'}</p></div><div class="type-tabs" role="group" aria-label="Media type"><button data-type="movie" class="${state.type === 'movie' ? 'selected' : ''}">${icon('movie', 17)} Movies</button><button data-type="tv" class="${state.type === 'tv' ? 'selected' : ''}">${icon('tv', 17)} TV shows</button>${state.query ? `<button data-type="all" class="${state.type === 'all' ? 'selected' : ''}">All</button>` : ''}</div></div>
  <div id="catalog-content">${skeleton()}</div>`;
  document.querySelectorAll('[data-type]').forEach(button => button.addEventListener('click', () => { state.type = button.dataset.type; state.page = 1; renderDiscover(); }));
  if (!state.query && state.type === 'all') state.type = 'movie';
  const version = ++catalogVersion;
  try {
    const type = state.type;
    const result = await api('catalog', undefined, { type, q: state.query, page: state.page });
    if (version !== catalogVersion || state.view !== 'discover') return;
    state.media = result.results; state.totalPages = result.total_pages;
    renderCatalog();
  } catch (error) {
    if (version !== catalogVersion || state.view !== 'discover') return;
    document.querySelector('#catalog-content').innerHTML = `<div class="empty-state">${icon('movie', 38)}<h2>Your catalog is almost here.</h2><p>${esc(error.message)}</p><button id="retry-catalog" class="button secondary">Try again</button></div>`;
    document.querySelector('#retry-catalog').addEventListener('click', renderDiscover);
  }
}
function fallbackPoster(media, index = 0) {
  return `<div class="poster-art tone-${index % 6}"><span class="poster-art-orb"></span><span class="poster-art-type">${media.type === 'movie' ? 'A MOTION PICTURE' : 'A TELEVISION SERIES'}</span><strong>${esc(media.title)}</strong><span class="poster-art-year">${esc(media.year)}</span></div>`;
}
function renderCatalog() {
  const content = document.querySelector('#catalog-content');
  if (!state.media.length) {
    content.innerHTML = `<div class="empty-state">${icon('search', 38)}<h2>No titles found.</h2><p>Try another title, or search using its original name.</p></div>`; return;
  }
  const featured = !state.query && state.page === 1 ? state.media.find(m => m.available && m.backdrop) || state.media.find(m => m.available) : null;
  content.innerHTML = `${featured ? `<section class="featured">${featured.backdrop ? `<img class="featured-image" src="${esc(featured.backdrop)}" alt="" fetchpriority="high">` : '<div class="featured-abstract"></div>'}<div class="featured-shade"></div><div class="featured-content"><span class="featured-label"><span class="live-dot"></span> TONIGHT’S PICK</span><h2>${esc(featured.title)}</h2><div class="metadata"><span>${esc(featured.year)}</span><span>${esc(featured.rating)}</span><span>${esc(featured.genres.slice(0, 2).join(' / '))}</span>${featured.score ? `<span class="score">${icon('star', 14)} ${featured.score}</span>` : ''}</div><p>${esc(featured.overview)}</p><button class="button primary" data-media="${featured.type}:${featured.id}">Explore this title ${icon('arrow', 18)}</button></div><div class="featured-side-label">A STORY WORTH STAYING IN FOR</div></section>` : ''}
  <div class="section-heading"><div><h2>${state.query ? 'Search results' : state.type === 'tv' ? 'New series. New obsessions.' : 'New to your screen'}</h2><p>${state.query ? `${state.media.length} titles on this page` : 'The latest releases, ready for your watchlist.'}</p></div><span class="muted small">${state.query ? '' : 'Home releases are verified before download'}</span></div>
  <div class="poster-grid">${state.media.map((m, i) => `<button class="media-card ${!m.available ? 'unavailable' : ''}" data-media="${m.type}:${m.id}" aria-label="${esc(m.title)}${!m.available ? ', Not out Yet' : ''}"><div class="poster-wrap">${m.poster ? `<img src="${esc(m.poster)}" alt="${esc(m.title)} poster" loading="lazy">` : fallbackPoster(m, i)}<div class="poster-overlay"><span>${icon('arrow', 22)}</span></div>${!m.available ? '<span class="unavailable-label">Not out Yet</span>' : `<span class="poster-badge">${m.type === 'tv' ? icon('tv', 12) : icon('movie', 12)} ${m.type === 'tv' ? 'SERIES' : 'MOVIE'}</span>`}${m.score ? `<span class="poster-score">${icon('star', 12)} ${m.score}</span>` : ''}</div><h3>${esc(m.title)}</h3><div class="card-meta"><span>${esc(m.year || 'TBA')}</span><span class="meta-dot">·</span><span>${esc(m.genres[0] || (m.type === 'tv' ? 'TV show' : 'Movie'))}</span></div></button>`).join('')}</div>
  <div class="pagination"><button class="button secondary" id="previous-page" ${state.page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${state.page} of ${state.totalPages || 1}</span><button class="button secondary" id="next-page" ${state.page >= state.totalPages ? 'disabled' : ''}>Next ${icon('arrow', 16)}</button></div>`;
  content.querySelectorAll('[data-media]').forEach(button => button.addEventListener('click', () => showMedia(button.dataset.media)));
  document.querySelector('#previous-page').addEventListener('click', () => { state.page--; renderDiscover(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  document.querySelector('#next-page').addEventListener('click', () => { state.page++; renderDiscover(); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  content.querySelectorAll('img').forEach(img => img.addEventListener('error', () => { img.hidden = true; }, { once: true }));
}
async function showMedia(key) {
  const [type, id] = key.split(':');
  const target = document.querySelector('#media-detail');
  target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="dialog-loading"><span class="spinner"></span><p>Checking this title…</p></div>`;
  if (!dialog.open) dialog.showModal(); bindClose(dialog);
  try {
    const m = await api('media', undefined, { type, id });
    if (!dialog.open) return;
    const inLibrary = m.type === 'movie' && m.library?.in_library;
    target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="detail-layout ${m.available ? '' : 'unavailable-detail'}"><div class="detail-poster">${m.poster ? `<img src="${esc(m.poster)}" alt="${esc(m.title)} poster">` : fallbackPoster(m)}</div><div class="detail-copy"><span class="eyebrow">${m.type === 'tv' ? 'TELEVISION SERIES' : 'MOVIE'}</span><h2>${esc(m.title)}</h2><div class="metadata"><span>${esc(m.year)}</span><span class="rating-badge">${esc(m.rating)}</span><span>${m.type === 'movie' ? `${m.runtime} min` : `${m.seasons.filter(s => s.season_number > 0).length} seasons`}</span>${m.score ? `<span class="score">${icon('star', 14)} ${m.score}</span>` : ''}</div><p class="detail-genres">${esc(m.genres.join(' · '))}</p><p class="detail-overview">${esc(m.overview || 'No synopsis available.')}</p><dl class="detail-facts"><div><dt>Release date</dt><dd>${esc(m.release_date || 'To be announced')}</dd></div><div><dt>Home release</dt><dd>${esc(m.home_release || 'Not confirmed')}</dd></div><div><dt>Original language</dt><dd>${esc(m.original_language.toUpperCase())}</dd></div></dl><div class="availability ${m.available ? 'available' : 'pending'}">${icon(m.available ? 'check' : 'clock', 16)} ${esc(m.availability_note)}</div>
    ${m.library?.in_library ? `<div class="availability available">${icon('check', 16)} ${m.type === 'tv' ? 'Series found in Jellyfin · season coverage may vary' : 'Already in your Jellyfin library'}</div>` : ''}
    <button id="request-media" class="button primary full" ${!m.available || inLibrary || state.demo ? 'disabled' : ''}>${icon('download', 18)} ${!m.available ? 'Not out Yet' : inLibrary ? 'In your library' : state.demo ? 'Downloads disabled in preview' : m.type === 'tv' ? 'Request complete seasons' : 'Request movie'}</button>
    <p class="detail-note">${m.type === 'tv' ? 'ScreenPort looks for an all-season pack first, then separate complete seasons. Seasons still airing are skipped.' : 'ScreenPort checks the title, language, quality, and size before choosing a download.'} An email update follows after downloads begin.</p></div></div>`;
    bindClose(dialog);
    document.querySelector('#request-media').addEventListener('click', async event => {
      event.currentTarget.disabled = true;
      try { const r = await api('request', { type: m.type, id: m.id }); dialog.close(); toast(r.message); navigate('downloads'); }
      catch (error) { toast(error.message, true); if (dialog.open) document.querySelector('#request-media').disabled = false; }
    });
  } catch (error) { target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="empty-state"><h2>Unable to open this title.</h2><p>${esc(error.message)}</p></div>`; bindClose(dialog); }
}
function bindClose(target) { target.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => target.close())); }
dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
accountDialog.addEventListener('click', event => { if (event.target === accountDialog) accountDialog.close(); });
searchLogDialog.addEventListener('click', event => { if (event.target === searchLogDialog) searchLogDialog.close(); });
const statusLabel = status => ({ queued: 'Queued', searching: 'Searching', selecting: 'Choosing a torrent', downloading: 'Downloading', complete: 'Ready to watch', failed: 'Needs attention' }[status] || status);
const eta = seconds => seconds === null ? 'Calculating remaining time' : seconds === 0 ? 'Complete' : seconds < 60 ? 'Less than a minute left' : seconds < 3600 ? `${Math.ceil(seconds / 60)} min remaining` : `${(seconds / 3600).toFixed(1)} hr remaining`;
async function renderDownloads() {
  document.querySelector('#page').innerHTML = `<div class="page-heading"><div><span class="eyebrow">ON THE WAY TO YOUR LIBRARY</span><h1>Your next movie night.</h1><p>${state.user.role === 'admin' ? 'All library requests and their progress.' : 'Your requests, from the first search to the final frame.'}</p></div><button class="button secondary" id="refresh-downloads">Refresh ${icon('arrow', 16)}</button></div><div id="downloads-content"><div class="loading-row"><span class="spinner"></span> Loading your requests…</div></div>`;
  document.querySelector('#refresh-downloads').addEventListener('click', () => loadDownloads()); await loadDownloads();
}
async function loadDownloads(silent = false) {
  try {
    const r = await api('downloads'); if (state.view !== 'downloads') return; state.downloads = r.requests;
    const active = r.requests.filter(r => !['complete', 'failed'].includes(r.status));
    const count = document.querySelector('#download-count'); count.hidden = !active.length; count.textContent = active.length;
    document.querySelector('#downloads-content').innerHTML = `<div class="stats-row"><div class="stat-card">${icon('download', 22)}<strong>${active.length}</strong><span>On their way</span></div><div class="stat-card">${icon('check', 22)}<strong>${r.requests.filter(r => r.status === 'complete').length}</strong><span>Ready to watch</span></div><div class="stat-card">${icon('clock', 22)}<strong>${r.requests.filter(r => r.status === 'failed').length}</strong><span>Need attention</span></div></div>
    ${r.requests.length ? `<div class="download-list">${r.requests.map(r => `<article class="download-row"><div class="download-poster">${r.media.poster ? `<img src="${esc(r.media.poster)}" alt="">` : fallbackPoster(r.media)}</div><div class="download-info"><div class="download-title"><h2>${esc(r.media.title)}</h2><span class="status-badge status-${esc(r.status)}">${esc(statusLabel(r.status))}</span></div><p class="muted small">${esc(r.media.year)} · ${r.media.type === 'tv' ? 'TV show' : 'Movie'} · ${esc(r.media.rating)}</p><p class="download-message">${esc(r.message)}</p><progress max="1" value="${Math.min(1, Math.max(0, Number(r.progress)))}" aria-label="Download progress for ${esc(r.media.title)}"></progress><div class="progress-meta"><span>${Math.round(r.progress * 100)}%</span><span>${r.status === 'downloading' ? esc(eta(r.eta)) : r.status === 'complete' ? 'Added to your media folder' : `${r.torrents.length} selected torrent${r.torrents.length !== 1 ? 's' : ''}`}</span><span>${r.speed ? `${(r.speed / 1024 ** 2).toFixed(1)} MiB/s` : ''}</span></div>
    ${r.torrents.length ? `<details><summary>Download details</summary>${r.torrents.map(t => `<p class="torrent-detail">${esc(t.name)}${state.user.role === 'admin' ? `<small>${(t.size / 1024 ** 3).toFixed(2)} GiB · ${t.seeders} seeders · ${t.peers} peers</small>` : ''}</p>`).join('')}</details>` : ''}
    ${r.email_status.some(e => e.status === 'failed') ? '<p class="form-error small">An email could not be delivered. An admin can retry it.</p>' : ''}</div>${state.user.role === 'admin' ? `<div class="download-actions"><button class="button secondary search-log" data-id="${r.id}">Search log</button>${r.status === 'failed' ? `<button class="button secondary retry-request" data-id="${r.id}">Retry</button>` : ''}</div>` : ''}</article>`).join('')}</div>` : `<div class="empty-state">${icon('download', 40)}<h2>A good story is on the horizon.</h2><p>Choose a movie or show in Discover. Your request will appear here.</p><button class="button primary" id="go-discover">Find something to watch ${icon('arrow')}</button></div>`}`;
    document.querySelector('#go-discover')?.addEventListener('click', () => navigate('discover'));
    document.querySelectorAll('.retry-request').forEach(button => button.addEventListener('click', async () => { button.disabled = true; try { const r = await api('admin-retry', { id: Number(button.dataset.id) }); toast(r.message); loadDownloads(); } catch (e) { toast(e.message, true); button.disabled = false; } }));
    document.querySelectorAll('.search-log').forEach(button => button.addEventListener('click', () => openSearchLog(Number(button.dataset.id))));
  } catch (error) { if (!silent) toast(error.message, true); }
}
async function openSearchLog(id) {
  if (state.user?.role !== 'admin') return;
  const target = document.querySelector('#search-log-detail');
  target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="search-log-content"><h2>Search log</h2><p class="muted">Loading search reports…</p></div>`;
  bindClose(searchLogDialog); if (!searchLogDialog.open) searchLogDialog.showModal();
  try {
    const data = await api('admin-search-log', undefined, { id });
    if (!searchLogDialog.open || state.user?.role !== 'admin') return;
    const labels = { searching: 'Searching', reviewing: 'Reviewing candidates', selected: 'Selected', no_candidates: 'All results filtered out', no_selection: 'Model chose none', error: 'Error', expired: 'Search disappeared', restarted: 'Restarted' };
    target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="search-log-content"><div class="section-heading"><div><span class="eyebrow">ADMIN SEARCH DIAGNOSTICS</span><h2>Request #${id}</h2></div><button class="button secondary" id="refresh-search-log">Refresh</button></div><p class="muted small">Newest first. Credentials and torrent links are excluded. Reports begin with this update.</p>${data.logs.length ? data.logs.map(log => {
      const r = log.report, f = r.filters, d = r.decision;
      const selected = new Set(r.outcome === 'selected' ? (d?.selected || []).map(s => s.candidate_id) : []);
      const evaluations = new Map((d?.evaluations || []).map(e => [e.candidate_id, e]));
      const outcome = row => {
        if (selected.has(row.candidate_id)) return 'Selected and validated';
        const evaluation = evaluations.get(row.candidate_id);
        if (row.status === 'model' && evaluation) return evaluation.verdict === 'alternative' ? 'Eligible alternative; not chosen' : evaluation.verdict === 'selected' ? 'Model choice; see overall validation outcome' : 'Model rejected';
        return row.reason;
      };
      return `<section class="search-log-report"><div class="download-title"><h3>${esc(labels[r.outcome] || r.outcome)}</h3><small>${esc(new Date(log.created_at * 1000).toLocaleString())}</small></div><p class="search-query"><strong>Query:</strong> ${esc(r.query)}</p><p class="muted small">${esc(r.media?.title)} · ${esc(r.media?.year)} · Original language: ${esc(r.media?.original_language || 'Unknown')} · Plugins: ${esc(r.plugins)}${r.wanted_seasons?.length ? ` · Seasons: ${esc(r.wanted_seasons.join(', '))}` : ''}</p><p>${esc(r.message)}</p>${r.total_found !== undefined ? `<p class="small">${Number(r.total_found)} results found${r.elapsed_seconds !== undefined ? ` after ${Number(r.elapsed_seconds)} seconds` : ''}.${f ? ` ${Number(f.received)} fetched; ${Number(f.sent_to_model)} eligible for model review.` : ''}</p>` : ''}${f && Number(r.total_found) > Number(f.received) ? '<p class="muted small">Only the first 500 results are fetched from qBittorrent.</p>' : ''}
      ${f && Object.keys(f.reasons).length ? `<ul class="search-filter-counts">${Object.entries(f.reasons).map(([reason, count]) => `<li><strong>${Number(count)}</strong> ${esc(reason)}</li>`).join('')}</ul>` : ''}
      ${d ? `<div class="search-decision"><strong>${d.model_called ? `Model explanation (${esc(d.model)})` : 'Model was not called'}</strong><p>${esc(d.summary || 'No explanation returned.')}</p>${d.audio_policy ? `<p class="small">Audio policy: ${esc(d.audio_policy)}</p>` : ''}${d.repair_reason ? `<p class="small">${esc(d.repair_reason)} Response attempts: ${Number(d.response_attempts)}.</p>` : ''}${d.selected.map(s => `<p class="small">${esc(s.quality)} · Confidence: ${Math.round(Number(s.confidence) * 100)}% · ${esc(s.reason)}</p>`).join('')}</div>` : ''}
      ${f?.rows.length ? `<details><summary>View ${f.rows.length} torrent results and filter outcomes</summary><div class="table-wrap"><table><thead><tr><th>Torrent</th><th>Size / seeders</th><th>Outcome</th></tr></thead><tbody>${f.rows.map(row => { const evaluation = evaluations.get(row.candidate_id); return `<tr><td><strong>${esc(row.name || '(Unnamed)')}</strong><small>${esc(row.engine || 'Unknown plugin')} · ${esc(row.link_type)}</small></td><td>${(Number(row.size_bytes) / 1024 ** 3).toFixed(2)} GiB<small>${Number(row.seeders)} seeders</small></td><td>${esc(outcome(row))}${evaluation ? `<small>${esc(evaluation.reason)}</small>` : row.status === 'model' && d?.model_called && !selected.has(row.candidate_id) ? '<small>No candidate-specific reason returned; see explanation above.</small>' : ''}${row.note ? `<small>${esc(row.note)}</small>` : ''}</td></tr>`; }).join('')}</tbody></table></div></details>` : ''}</section>`;
    }).join('') : '<div class="empty-state"><h3>No search report yet.</h3><p>Retry this request to capture the search results and selection explanation.</p></div>'}</div>`;
    bindClose(searchLogDialog);
    target.querySelector('#refresh-search-log').addEventListener('click', () => openSearchLog(id));
  } catch (error) {
    target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="search-log-content"><h2>Unable to load search log.</h2><p>${esc(error.message)}</p></div>`;
    bindClose(searchLogDialog);
  }
}
async function renderAdmin() {
  const page = document.querySelector('#page');
  page.innerHTML = `<div class="page-heading"><div><span class="eyebrow">BEHIND THE SCENES</span><h1>Keep your library running.</h1><p>Manage your people, connections, and download preferences.</p></div><button class="button secondary" id="check-connections">Check connections ${icon('arrow', 16)}</button></div><div id="connection-results"></div><div class="admin-tabs" role="group" aria-label="Administration section">${['accounts', 'settings', 'activity'].map(tab => `<button data-admin-tab="${tab}" class="${state.adminTab === tab ? 'selected' : ''}">${tab[0].toUpperCase() + tab.slice(1)}</button>`).join('')}</div><div id="admin-content"><div class="loading-row"><span class="spinner"></span> Loading admin settings…</div></div>`;
  document.querySelectorAll('[data-admin-tab]').forEach(button => button.addEventListener('click', () => { state.adminTab = button.dataset.adminTab; renderAdmin(); }));
  document.querySelector('#check-connections').addEventListener('click', async event => {
    event.currentTarget.disabled = true; const node = document.querySelector('#connection-results'); node.innerHTML = '<p class="muted">Checking services without sending email or starting downloads…</p>';
    try { const r = await api('admin-connections', {}); if (state.view === 'admin') node.innerHTML = `<div class="connection-grid">${Object.entries(r.checks).map(([name, c]) => `<div class="connection-card ${c.ok ? 'connected' : 'disconnected'}">${icon(c.ok ? 'check' : 'clock', 17)}<strong>${esc(name)}</strong><small>${esc(c.message)}</small></div>`).join('')}</div>`; }
    catch (e) { toast(e.message, true); } finally { document.querySelector('#check-connections')?.removeAttribute('disabled'); }
  });
  try { const data = await api('admin'); if (state.view === 'admin') renderAdminContent(data); } catch (error) { toast(error.message, true); }
}
function renderAdminContent(data) {
  const target = document.querySelector('#admin-content');
  const workerOk = data.worker_seen && Date.now() / 1000 - data.worker_seen < 180;
  const health = `<div class="notice ${workerOk && data.ready ? 'good' : ''}">${icon('shield', 17)}<span>${data.ready ? 'Required connections are configured.' : 'Complete required catalog, downloader, AI, and email settings to enable downloads.'} ${workerOk ? 'Background worker is active.' : 'Background worker has not checked in recently. Start it or configure the cron task.'}</span></div>`;
  if (state.adminTab === 'accounts') {
    target.innerHTML = `${health}<div class="section-heading"><div><h2>Your people</h2><p>${data.users.filter(u => u.status === 'pending').length} account requests waiting for approval</p></div><button class="button primary" id="create-account">${icon('user', 16)} New account</button></div><div class="table-wrap"><table><thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Manage</th></tr></thead><tbody>${data.users.map(u => `<tr><td><strong>${esc(u.username)}</strong><small>${esc(u.email)}</small></td><td>${esc(u.role)}</td><td><span class="status-badge status-${esc(u.status)}">${esc(u.status)}</span></td><td><button class="text-button edit-user" data-id="${u.id}">${u.status === 'pending' ? 'Review request' : 'Edit account'}</button></td></tr>`).join('')}</tbody></table></div>`;
    document.querySelector('#create-account').addEventListener('click', () => editUser());
    target.querySelectorAll('.edit-user').forEach(b => b.addEventListener('click', () => editUser(data.users.find(u => u.id === Number(b.dataset.id)))));
  }
  if (state.adminTab === 'settings') {
    const groups = [
      ['Catalog & selection', ['TMDB_READ_ACCESS_TOKEN', 'REGION', 'TIMEZONE', 'OPENAI_API_KEY', 'OPENAI_MODEL', 'SELECTION_MIN_CONFIDENCE', 'ASSUME_ORIGINAL_AUDIO']],
      ['Download connections', ['QBITTORRENT_URL', 'QBITTORRENT_USERNAME', 'QBITTORRENT_PASSWORD', 'QBITTORRENT_SEARCH_PLUGINS', 'TORRENT_ALLOWED_HOSTS', 'JELLYFIN_URL', 'JELLYFIN_API_KEY']],
      ['Folders & quality', ['MOVIE_ROOT', 'MOVIE_EXISTING_FOLDERS', 'ALLOW_NEW_MOVIE_FOLDERS', 'MOVIE_FOLDER_OVERRIDES', 'MOVIE_GENRE_MAP', 'TV_ROOT', 'MATURE_TV_ROOT', 'MATURE_RATINGS', 'UNKNOWN_RATING_MATURE', 'MAX_TORRENT_GB', 'TV_MAX_GIB_PER_HOUR', 'SEARCH_TIMEOUT']],
      ['Email notifications', ['DOWNLOAD_MANAGER_EMAIL', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME']],
      ['Access & requests', ['REGISTRATION_OPEN', 'DOWNLOADS_ENABLED', 'REQUEST_LIMIT_PER_DAY']],
    ];
    const field = key => {
      const f = data.settings.find(f => f.key === key); const booleans = ['REGISTRATION_OPEN', 'DOWNLOADS_ENABLED', 'UNKNOWN_RATING_MATURE', 'ALLOW_NEW_MOVIE_FOLDERS', 'ASSUME_ORIGINAL_AUDIO'];
      return `<label>${esc(f.label)}${f.secret ? `<span class="secret-state">${f.configured ? 'Configured · enter to replace' : 'Not configured'}</span><input name="${key}" type="password" autocomplete="new-password" placeholder="${f.configured ? 'Saved securely' : 'Enter credential'}" value="">` : booleans.includes(key) ? `<select name="${key}"><option value="true" ${f.value === 'true' ? 'selected' : ''}>Enabled</option><option value="false" ${f.value === 'false' ? 'selected' : ''}>Disabled</option></select>` : ['MOVIE_GENRE_MAP', 'MOVIE_FOLDER_OVERRIDES', 'MOVIE_EXISTING_FOLDERS'].includes(key) ? `<textarea name="${key}" rows="3">${esc(f.value)}</textarea>` : `<input name="${key}" value="${esc(f.value)}" ${key.includes('EMAIL') || key === 'MAIL_FROM_ADDRESS' ? 'type="email"' : ''}>`}</label>`;
    };
    target.innerHTML = `${health}<form id="settings-form"><p class="muted small">Secret fields are never displayed. Leave them blank to keep the saved value.</p>${groups.map(([title, keys]) => `<section class="settings-section"><h2>${title}</h2><div class="settings-grid">${keys.map(field).join('')}</div></section>`).join('')}<div class="settings-save"><button class="button primary" type="submit">Save settings ${icon('check', 18)}</button></div></form>`;
    document.querySelector('#settings-form').addEventListener('submit', async event => { event.preventDefault(); const button = event.currentTarget.querySelector('button[type=submit]'); button.disabled = true; try { const r = await api('admin-settings', { settings: Object.fromEntries(new FormData(event.currentTarget)) }); toast(r.message); renderAdmin(); } catch (e) { toast(e.message, true); button.disabled = false; } });
  }
  if (state.adminTab === 'activity') {
    target.innerHTML = `${health}${data.email_failures ? `<div class="notice"><span>${data.email_failures} email deliveries need attention.</span><button id="retry-emails" class="text-button">Retry failed emails</button></div>` : ''}<div class="section-heading"><div><h2>Recent activity</h2><p>Account changes, requests, and background task results.</p></div></div><div class="activity-list">${data.audit.length ? data.audit.map(a => `<div><span class="activity-icon">${icon('clock', 16)}</span><p><strong>${esc(a.action.replaceAll('_', ' '))}</strong><span>${esc(a.detail || (a.username ? `By ${a.username}` : 'Background worker'))}</span></p><time>${esc(new Date(a.created_at * 1000).toLocaleString())}</time></div>`).join('') : '<p class="muted">No activity yet.</p>'}</div>`;
    document.querySelector('#retry-emails')?.addEventListener('click', async () => { try { const r = await api('admin-email-retry', {}); toast(r.message); renderAdmin(); } catch (e) { toast(e.message, true); } });
  }
}
function editUser(user) {
  const target = document.querySelector('#account-detail');
  target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="account-content"><span class="eyebrow">LIBRARY ACCESS</span><h2>${user ? esc(user.username) : 'Invite someone in.'}</h2><p class="muted">${user ? esc(user.email) : 'Create an approved account for your library.'}</p><form id="user-form">${user ? `<input name="id" type="hidden" value="${user.id}">` : '<label>Username<input name="username" minlength="3" maxlength="40" autocomplete="off" required></label><label>Email address<input name="email" type="email" required></label>'}<label>${user ? 'New password (optional)' : 'Password'}<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" ${user ? '' : 'required'}></label><label>Role<select name="role"><option value="user" ${user?.role === 'user' ? 'selected' : ''}>Member</option><option value="admin" ${user?.role === 'admin' ? 'selected' : ''}>Admin</option></select></label>${user ? `<label>Status<select name="status"><option value="approved" ${user.status === 'approved' || user.status === 'pending' ? 'selected' : ''}>Approved</option><option value="pending">Pending</option><option value="disabled" ${user.status === 'disabled' ? 'selected' : ''}>Disabled</option></select></label>` : ''}<button class="button primary full" type="submit">${user ? 'Save account' : 'Create account'} ${icon('check', 17)}</button></form></div>`;
  accountDialog.showModal(); bindClose(accountDialog);
  document.querySelector('#user-form').addEventListener('submit', async event => { event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget)); if (user) data.id = Number(data.id); try { const r = await api(user ? 'admin-user-update' : 'admin-user-create', data); accountDialog.close(); toast(r.message); renderAdmin(); } catch (e) { toast(e.message, true); } });
}
function profile() {
  const target = document.querySelector('#account-detail');
  target.innerHTML = `<button class="dialog-close" data-close aria-label="Close">${icon('close')}</button><div class="account-content"><span class="eyebrow">YOUR ACCOUNT</span><h2>${esc(state.user.username)}</h2><p class="muted">${esc(state.user.email)}</p><form id="password-form"><h3>Change your password</h3><label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label><label>New password<input name="new_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button class="button secondary full" type="submit">Update password</button></form><button class="button signout full" id="signout">${icon('logout', 17)} Sign out</button></div>`;
  accountDialog.showModal(); bindClose(accountDialog);
  document.querySelector('#signout').addEventListener('click', async () => { try { await api('logout', {}); state.user = null; accountDialog.close(); clearInterval(downloadTimer); boot(); } catch (e) { toast(e.message, true); } });
  document.querySelector('#password-form').addEventListener('submit', async event => { event.preventDefault(); try { const r = await api('profile', Object.fromEntries(new FormData(event.currentTarget))); state.user = null; accountDialog.close(); clearInterval(downloadTimer); await boot(); toast(r.message); } catch (e) { toast(e.message, true); } });
}
boot();
