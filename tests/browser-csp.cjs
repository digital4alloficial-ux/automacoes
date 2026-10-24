// Optional real-browser regression. Requires Node, Playwright and Chromium.
// Runs entirely locally, substituting a second local origin for Bling.
// The PHP integration suite separately verifies the exact real CSP origin header.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const base = fs.readFileSync(path.join(root, 'private/bootstrap.php'), 'utf8');
const connect = fs.readFileSync(path.join(root, 'public/bling/connect.php'), 'utf8');
function policy(source) {
  const found = source.match(/header\("Content-Security-Policy: ([^"\n]+)"\)/);
  assert(found, 'CSP header must exist');
  return found[1].replace('$authorizeOrigin', 'https://www.bling.com.br');
}
const policies = { old: policy(base), fixed: policy(connect) };
let targetOrigin;
const target = http.createServer((req, res) => { res.setHeader('Content-Type', 'text/html'); res.end('<title>Mock Bling</title><h1>Autorização simulada</h1>'); });
const server = http.createServer((req, res) => {
  const mode = req.url === '/old' ? 'old' : 'fixed';
  res.setHeader('Content-Security-Policy', policies[mode].replace('https://www.bling.com.br', targetOrigin));
  res.setHeader('Cache-Control', 'no-store');
  if (req.method === 'POST') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      assert.equal(new URLSearchParams(body).get('csrf'), 'fixture-csrf');
      res.writeHead(303, { Location: targetOrigin + '/fixture-authorize' }); res.end();
    });
    return;
  }
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end('<!doctype html><form method="post"><input type="hidden" name="csrf" value="fixture-csrf"><button>Autorizar</button></form>');
});
(async () => {
  let browser;
  await new Promise(resolve => target.listen(0, '127.0.0.1', resolve));
  targetOrigin = `http://127.0.0.1:${target.address().port}`;
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
    const context = await browser.newContext();
    await context.route('**/*', route => {
      const u = new URL(route.request().url());
      if (u.hostname === '127.0.0.1') return route.continue();
      return route.abort();
    });
    const page = await context.newPage();
    await page.addInitScript(() => {
      window.formBlocked = false;
      document.addEventListener('securitypolicyviolation', e => {
        if (e.effectiveDirective === 'form-action') window.formBlocked = true;
      });
    });
    const origin = `http://127.0.0.1:${server.address().port}`;
    await page.goto(origin + '/old');
    await page.getByRole('button', { name: 'Autorizar' }).click();
    await page.waitForFunction(() => window.formBlocked, { timeout: 5000 });
    assert.equal(page.url(), origin + '/old');
    console.log('PASS Chromium reproduz bloqueio do redirect com CSP anterior');
    await page.goto(origin + '/fixed');
    await Promise.all([
      page.waitForURL(targetOrigin + '/fixture-authorize'),
      page.getByRole('button', { name: 'Autorizar' }).click(),
    ]);
    assert.equal(await page.title(), 'Mock Bling');
    console.log('PASS Chromium aceita redirect externo com política corrigida');
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
    await new Promise(resolve => target.close(resolve));
  }
})().catch(e => { console.error(e.message); process.exitCode = 1; });
