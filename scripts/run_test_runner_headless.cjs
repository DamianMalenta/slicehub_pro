#!/usr/bin/env node
/** Headless runner for tests/test_runner.html (agent/CI).
 *  Wymaga puppeteer-core poza repo: `cd /tmp && npm install puppeteer-core`
 *  lub globalnie. Nie dodajemy package.json do SliceHub (Zero-Reload).
 */
function loadPuppeteer() {
  try { return require('puppeteer-core'); } catch (_) { /* local/global */ }
  try { return require('/tmp/node_modules/puppeteer-core'); } catch (_) { /* agent */ }
  console.error('Brak puppeteer-core. Uruchom: cd /tmp && npm install puppeteer-core');
  process.exit(2);
}
const puppeteer = loadPuppeteer();

const URL = process.env.TEST_RUNNER_URL || 'http://localhost/slicehub/tests/test_runner.html';
const CHROME = process.env.CHROME_PATH || '/usr/local/bin/google-chrome';

(async () => {
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
      return { pass, fail, warn, total, badge };
    });
    console.log(JSON.stringify(summary, null, 2));
    process.exit((parseInt(summary.fail, 10) || 0) > 0 ? 1 : 0);
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(2);
});
