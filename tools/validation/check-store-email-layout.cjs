// Review only fictional captured WooCommerce HTML. No authenticated browser or outgoing network.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.WPHUB_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(process.argv[2]);
const files = fs.readdirSync(root).filter(name => /^acknowledgement-(hpos|legacy)-(en|fr|fr-pending)\.html$/.test(name));
if (files.length !== 6) throw new Error('Both real storage modes and all three captured email cases are required.');
const out = path.join(root, 'browser-evidence');
fs.mkdirSync(out, { recursive: true });
const server = http.createServer((req, res) => {
  const name = decodeURIComponent(new URL(req.url, 'http://127.0.0.1').pathname.slice(1));
  if (!files.includes(name)) { res.writeHead(404); res.end(); return; }
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  fs.createReadStream(path.join(root, name)).pipe(res);
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ executablePath: process.env.WPHUB_CHROME_PATH, headless: true });
  const results = [];
  try {
    for (const file of files) {
      for (const [device, width] of [['desktop', 1000], ['mobile', 360]]) {
        const context = await browser.newContext({ viewport: { width, height: 900 } });
        await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
        const page = await context.newPage();
        await page.goto(`${origin}/${file}`, { waitUntil: 'load' });
        const evidence = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, text: document.body.innerText }));
        const screenshot = file.replace('.html', `-${device}.png`);
        await page.screenshot({ path: path.join(out, screenshot), fullPage: true });
        results.push({ file, device, width, scroll: evidence.scroll, screenshot,
          checks: { no_horizontal_overflow: evidence.scroll <= width + 1,
            brand_visible: evidence.text.includes('Fictional Brand'),
            appropriate_document_notice: file.includes('pending') ? evidence.text.includes('Cet e-mail ne contient pas vos documents') : /separate email|prochain e-mail/.test(evidence.text),
            no_payment_claim_if_pending: !file.includes('pending') || (evidence.text.includes('Le paiement n’est pas encore confirmé') && !/Your payment has been received|Votre paiement a bien été reçu/.test(evidence.text)),
          } });
        await context.close();
      }
    }
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
  const failed = results.filter(result => Object.values(result.checks).includes(false));
  const report = { engine:'Clean Chromium, captured native WooCommerce HTML; not Gmail/Outlook rendering', external_requests_allowed:false,
    count:results.length, failed:failed.length, results };
  fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
  if (failed.length) process.exitCode = 1;
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
