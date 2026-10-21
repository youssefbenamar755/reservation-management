// Isolated artifact QA. No authenticated browser, application database or email transport.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require(process.env.WPHUB_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(process.argv[2]);
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
const out = path.join(root, 'browser-evidence');
fs.mkdirSync(out, { recursive: true });
const server = http.createServer((req, res) => {
  const file = path.resolve(root, '.' + decodeURIComponent(new URL(req.url, 'http://127.0.0.1').pathname));
  if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile() || !file.endsWith('.html')) {
    res.writeHead(404); res.end(); return;
  }
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  fs.createReadStream(file).pipe(res);
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  const browser = await chromium.launch({ executablePath: process.env.WPHUB_CHROME_PATH, headless: true });
  const results = [];
  const logos = new Map();
  try {
    for (const site of manifest.websites) {
      for (const sample of site.samples) {
        for (const images of ['normal', 'blocked']) {
          for (const [device, width] of [['desktop', 1000], ['mobile', 360]]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            await context.route('**/*', route => {
              const url = route.request().url();
              if (new URL(url).origin === `http://127.0.0.1:${port}`) return route.continue();
              if (images === 'normal' && url === site.same_site_registry_identity.logo_url) return route.continue();
              return route.abort();
            });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('response', response => {
              if (response.url() === site.same_site_registry_identity.logo_url) logos.set(response.url(), response.status());
            });
            const relative = images === 'normal' ? sample.html : sample.images_blocked_html;
            await page.goto(`http://127.0.0.1:${port}/${relative}`, { waitUntil: 'load', timeout: 30000 });
            const evidence = await page.evaluate(() => ({
              viewport: innerWidth, scroll: document.documentElement.scrollWidth,
              text: document.body.innerText,
              images: [...document.images].map(img => ({src: img.src, alt: img.alt, loaded: img.complete && img.naturalWidth > 0})),
              links: [...document.querySelectorAll('a')].map(a => ({text:a.innerText, href:a.href})),
            }));
            const screenshot = `website-${site.website_id}-${sample.language}-${sample.kind}-${sample.attachments.length}-${images}-${device}.png`;
            await page.screenshot({ path: path.join(out, screenshot), fullPage: true });
            const assertions = {
              no_horizontal_overflow: evidence.scroll <= width + 1,
              brand_visible: evidence.text.includes(site.same_site_registry_identity.brand_name),
              draft_notice: /DRAFT|BROUILLON/.test(evidence.text),
              attachment_names_visible: sample.attachments.every(a => evidence.text.includes(a.name)),
              no_fake_download_link: evidence.links.every(a => !/download|télécharg/i.test(a.text)),
              no_script_errors: errors.length === 0,
            };
            results.push({ website_id:site.website_id, language:sample.language, kind:sample.kind, attachments:sample.attachments.length,
              images, device, assertions, screenshot, loaded_images:evidence.images, errors });
            await context.close();
          }
        }
      }
    }
  } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
  const failed = results.filter(row => Object.values(row.assertions).includes(false));
  const report = { checked_at:new Date().toISOString(), engine:'Clean headless Chromium; browser layout only, not Gmail/Outlook',
    public_logo_http_statuses:Object.fromEntries(logos), count:results.length, failed:failed.length, results };
  fs.writeFileSync(path.join(out, 'results.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({count:results.length, failed:failed.length, logo_statuses:Object.fromEntries(logos), evidence:out}));
  if (failed.length) process.exitCode = 1;
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
