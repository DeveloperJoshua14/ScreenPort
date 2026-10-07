const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const code = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/app.js'), 'utf8');
const escape = code.split('\n').find(line => line.startsWith('const esc ='));
const start = code.indexOf('const statusLabel =');
const end = code.indexOf('async function openSearchLog(', start);
const nodes = {}, buttons = [], handlers = {};
function node(key) { return nodes[key] ||= { innerHTML: '', addEventListener() {} }; }
const calls = []; let confirmation = true;
const request = { id: 1, status: 'failed', media: { title: '<script>Title</script>', type: 'movie', year: '2025', rating: 'PG' }, progress: 0, eta: null, speed: 0, message: 'No candidates', torrents: [], email_status: [] };
const context = vm.createContext({ state: { user: { role: 'admin' }, view: 'downloads', showRemoved: false, downloads: [request] }, document: { querySelector: node, querySelectorAll: selector => selector === '.download-control' ? buttons : [] }, icon: () => '', fallbackPoster: () => '', toast() {}, navigate() {}, openSearchLog() {}, window: { confirm: () => confirmation }, api: async (action, body, params) => { calls.push([action, body, params]); return action === 'downloads' ? { requests: [request] } : { message: 'Queued' }; } });
vm.runInContext(escape + '\n' + code.slice(start, end), context);
context.request = request;
let checks = 0;
function check(ok) { assert(ok); checks++; }
let html = vm.runInContext('downloadControls(request)', context);
check(html.includes('Suspend') && html.includes('Remove request') && html.includes('Retry'));
context.state.user.role = 'user'; check(vm.runInContext('downloadControls(request)', context) === ''); context.state.user.role = 'admin';
request.status = 'suspended'; html = vm.runInContext('downloadControls(request)', context); check(html.includes('Resume') && html.includes('Remove request') && !html.includes('>Retry<'));
request.status = 'removed'; html = vm.runInContext('downloadControls(request)', context); check(html.includes('Restore request') && !html.includes('Remove request'));
request.status = 'complete'; html = vm.runInContext('downloadControls(request)', context); check(!html.includes('Suspend') && html.includes('Remove request'));
request.status = 'removing'; html = vm.runInContext('downloadControls(request)', context); check(html.includes('Removal queued') && !html.includes('download-control'));
request.status = 'failed';
const button = { dataset: { id: '1', command: 'remove' }, disabled: false, addEventListener(event, fn) { handlers[event] = fn; } }; buttons.push(button);
(async () => {
  await vm.runInContext('loadDownloads()', context);
  check(!node('#downloads-content').innerHTML.includes('<script>'));
  confirmation = false; await handlers.click(); check(!calls.some(([action]) => action === 'admin-download-control'));
  confirmation = true; await handlers.click(); check(calls.some(([action, body]) => action === 'admin-download-control' && body.id === 1 && body.command === 'remove'));
  context.state.showRemoved = true; await vm.runInContext('loadDownloads()', context); check(calls.at(-1)[2].include_removed === 1);
  context.state.user.role = 'user'; await vm.runInContext('loadDownloads()', context); check(!('include_removed' in calls.at(-1)[2]));
  console.log(`PASS: ${checks} admin controls, confirmation, filter and renderer checks.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
