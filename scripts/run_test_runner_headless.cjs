#!/usr/bin/env node
/** Headless runner for tests/test_runner.html (agent/CI).
 *  Wymaga puppeteer-core poza repo: `cd /tmp && npm install puppeteer-core`
 *  lub globalnie. Nie dodajemy package.json do SliceHub (Zero-Reload).
 */
const { execSync } = require('child_process');
const path = require('path');

function loadPuppeteer() {
  try { return require('puppeteer-core'); } catch (_) { /* local/global */ }
  try { return require('/tmp/node_modules/puppeteer-core'); } catch (_) { /* agent */ }
  console.error('Brak puppeteer-core. Uruchom: cd /tmp && npm install puppeteer-core');
  process.exit(2);
}
const puppeteer = loadPuppeteer();

const URL = process.env.TEST_RUNNER_URL || 'http://localhost/slicehub/tests/test_runner.html';
const CHROME = process.env.CHROME_PATH || '/usr/local/bin/google-chrome';

/**
 * Deterministyczny stan DB przed suite'ami (kanoniczna weryfikacja headless):
 *   • czyści sh_panic_log — eliminuje flakiness T58 (2-min debounce PanicEngine);
 *   • wstawia WZ(+)/KOR(−) powiązane z completed order — asercja T80 (KOR redukuje COGS).
 * Wywoływane przez CLI (php); brak php = testy i tak zawiodą, więc tylko logujemy.
 */
function runHeadlessSetup() {
  const setupScript = path.join(__dirname, 'test_setup_headless.php');
  try {
    const out = execSync(`php "${setupScript}"`, { encoding: 'utf8', timeout: 30000 });
    const j = JSON.parse(out.trim().split('\n').pop() || out);
    if (j && j.success) {
      console.log('[headless-setup] OK:', j.message);
      (j.steps || []).forEach((s) => console.log(`  • ${s.step}: ${s.ok ? 'ok' : 'FAIL'}${s.error ? ' — ' + s.error : ''}`));
    } else {
      console.warn('[headless-setup] Setup reported failure:', (j && j.message) || out.trim());
    }
  } catch (e) {
    console.warn('[headless-setup] Nie udało się uruchomić php test_setup_headless.php:', e.message);
  }
}

(async () => {
  // Deterministyczny stan DB (panic_log + dane COGS dla T80) przed suite'ami.
  runHeadlessSetup();

  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  try {
    const page = await browser.newPage();
    page.on('console', (msg) => {
      const t = msg.type();
      if (t === 'warning' || t === 'error') console.log(`[browser:${t}]`, msg.text());
    });
    await page.goto(URL, { waitUntil: 'networkidle2', timeout: 120000 });
    await page.waitForSelector('#btn-run-all', { timeout: 30000 });
    await page.waitForFunction(
      () => typeof globalThis.runAllTests === 'function' || document.querySelectorAll('[id^="suite-"]').length > 0,
      { timeout: 30000 },
    );
    await page.click('#btn-run-all');
    await page.waitForFunction(
      () => {
        const btn = document.getElementById('btn-run-all');
        const total = parseInt(document.getElementById('stat-total')?.textContent || '0', 10);
        return btn && !btn.disabled && total > 0;
      },
      { timeout: 180000 },
    );
    const summary = await page.evaluate(() => {
      const pass = document.getElementById('stat-pass')?.textContent?.trim() || '0';
      const fail = document.getElementById('stat-fail')?.textContent?.trim() || '0';
      const warn = document.getElementById('stat-warn')?.textContent?.trim() || '0';
      const total = document.getElementById('stat-total')?.textContent?.trim() || '0';
      const badge = document.getElementById('summary-badge')?.textContent?.trim() || '';
      // Per-test breakdown for debugging
      const rows = document.querySelectorAll('[id^="test-"]');
      const details = [];
      rows.forEach(row => {
        const id = row.id.replace('test-', '');
        const badge = row.querySelector('[id^="badge-"]')?.textContent?.trim() || '?';
        const label = row.querySelector('[id^="label-"]')?.textContent?.trim() || '';
        const detail = row.querySelector('[id^="detail-"]')?.textContent?.trim() || '';
        if (badge === 'FAIL' || badge === 'WARN') {
          details.push({ id, badge, label: label.slice(0, 80), detail: detail.slice(0, 200) });
        }
      });
      return { pass, fail, warn, total, badge, details };
    });
    console.log(JSON.stringify(summary, null, 2));
    if (summary.details && summary.details.length > 0) {
      console.log('\n--- FAILED/WARNED TESTS ---');
      summary.details.forEach(d => {
        console.log(`[${d.badge}] ${d.id}: ${d.label}`);
        if (d.detail) console.log(`  → ${d.detail}`);
      });
    }
    process.exit((parseInt(summary.fail, 10) || 0) > 0 ? 1 : 0);
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(2);
});
