// Chromium layout simulations of actual PHP templates, not Gmail/Outlook/live SES acceptance.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');
let browser, messages;
before(async () => {
  messages = JSON.parse(execFileSync(process.env.CARLINK_TEST_PHP || 'php', [path.join(__dirname, 'render-email.php')], { encoding: 'utf8' }));
  browser = await chromium.launch({ headless: true });
});
after(async () => { await browser?.close(); });
test('all stakeholder preview templates are generated with expected security and magic-link content', () => {
  for (const required of ['login', 'security', 'status', 'status_activated', 'fleet', 'appeal', 'compliance', 'device_linked']) {
    assert.ok(messages[required], `Missing synthetic email preview: ${required}`);
  }
  assert.ok(messages.login.html.includes('Single-use'));
  assert.ok(messages.login.html.includes('Expires in 10 minutes'));
  assert.ok(messages.login.html.includes('Sign in to CarLink'));
  assert.ok(!messages.security.html.includes('<script>'));
  assert.ok(messages.fleet.body.includes('Not available'));
});
for (const width of [320, 390, 600, 1440]) test(`actual HTML emails fit ${width}px light-theme viewports without scripts or external assets`, async () => {
  const page = await browser.newPage({ viewport: { width, height: 1000 } });
  const requests = []; page.on('request', request => requests.push(request.url()));
  try {
    for (const [name, message] of Object.entries(messages)) {
      await page.setContent(message.html);
      const layout = await page.evaluate(() => ({
        scroll: document.documentElement.scrollWidth, width: window.innerWidth,
        card: document.querySelector('.card').getBoundingClientRect().width,
        padding: getComputedStyle(document.querySelector('.card')).paddingLeft,
        background: getComputedStyle(document.querySelector('.card')).backgroundColor,
        link: [...document.querySelectorAll('a')].map(a => a.href),
        script: document.querySelectorAll('script, img, link, iframe').length,
      }));
      assert.ok(layout.scroll <= layout.width, `${name} overflows: ${JSON.stringify(layout)}`);
      assert.ok(layout.card <= 600); assert.equal(layout.background, 'rgb(255, 255, 255)');
      assert.equal(layout.padding, width <= 600 ? '20px' : '36px'); assert.equal(layout.script, 0);
      assert.ok(layout.link.every(url => url.startsWith('https://carlink.example.test/')));
      assert.ok(message.body.includes('CarLink'));
      if (process.env.CARLINK_EMAIL_PREVIEW_DIR) {
        fs.mkdirSync(process.env.CARLINK_EMAIL_PREVIEW_DIR, { recursive: true });
        await page.screenshot({ path: path.join(process.env.CARLINK_EMAIL_PREVIEW_DIR, `${name}-${width}.png`), fullPage: true });
      }
    }
    assert.deepEqual(requests, []);
  } finally { await page.close(); }
});
