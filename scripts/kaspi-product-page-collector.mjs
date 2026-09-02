import { pathToFileURL } from 'node:url';

export function validProductUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && (url.hostname === 'kaspi.kz' || url.hostname.endsWith('.kaspi.kz'))
      && !url.username && !url.password && !url.port && !url.search && !url.hash
      && /^\/shop\/p\/[^/]+-[0-9]+\/$/.test(url.pathname);
  } catch { return false; }
}
export function pageReason({url, status, html, captcha}, expectedUrl) {
  if (!validProductUrl(expectedUrl)) return 'wrong_product';
  if (captcha) return 'captcha_detected';
  try {
    const parsed = new URL(url);
    parsed.search = ''; parsed.hash = '';
    if (parsed.href !== expectedUrl) return 'wrong_product';
  } catch { return 'wrong_product'; }
  if (!Number.isInteger(status) || status < 200 || status >= 300) return 'collector_http_failed';
  if (!html || !html.trim() || !/<(?:body|script|h1)\b/i.test(html)) return 'collector_empty_html';
  if (Buffer.byteLength(html) > 4000000) return 'collector_html_too_large';
  return null;
}

async function captchaPresent(page) {
  for (const frame of page.frames()) {
    const detected = await frame.evaluate(() => {
      const text = document.body?.innerText || '';
      const visible = selector => [...document.querySelectorAll(selector)].some(el => el.getClientRects().length);
      return /captcha|капча|verify you are human|подтвердите.{0,30}робот|я не робот/iu.test(text)
        || visible('[class*="captcha"], [id*="captcha"], iframe[src*="captcha"]');
    }).catch(() => true);
    if (detected) return true;
  }
  return false;
}

async function main() {
  const arg = name => process.argv.find(value => value.startsWith(`--${name}=`))?.slice(name.length + 3);
  let browser;
  let result = {status:'failed', reason:'collector_failed', captcha:false};
  try {
    if (process.platform !== 'win32' || arg('headless') !== 'false') throw Error('local_browser_disabled');
    const expectedUrl = arg('url');
    if (!validProductUrl(expectedUrl)) throw Error('wrong_product');
    const { chromium } = await import('playwright');
    browser = await chromium.launch({headless:false, timeout:15000});
    const context = await browser.newContext({locale:'ru-RU', viewport:{width:1440,height:1000}});
    const page = await context.newPage();
    // Two bounded attempts for slow navigation/content. Never retry a CAPTCHA or identity failure.
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const response = await page.goto(expectedUrl, {waitUntil:'domcontentloaded',timeout:30000});
        if (await captchaPresent(page)) throw Error('captcha_detected');
        await page.waitForFunction(() => Boolean(window.BACKEND?.components?.item?.card?.title)
          || (document.querySelector('h1') && document.querySelector('meta[property="og:image"]')), null, {timeout:15000});
        const html = await page.content();
        const captcha = await captchaPresent(page);
        const reason = pageReason({url:page.url(),status:response?.status() ?? 0,html,captcha}, expectedUrl);
        if (reason) throw Error(reason);
        result = {status:'ok', final_url:expectedUrl, http_status:response.status(), html, captcha:false};
        break;
      } catch (error) {
        if (['captcha_detected','wrong_product','collector_html_too_large'].includes(error.message)) throw error;
        if (attempt === 1) throw Error(error.name === 'TimeoutError' ? 'collector_timeout' : 'collector_empty_or_unavailable');
      }
    }
  } catch (error) {
    const reasons = ['captcha_detected','wrong_product','local_browser_disabled','collector_timeout','collector_empty_or_unavailable','collector_html_too_large'];
    result = {status:'failed', reason:reasons.includes(error.message) ? error.message : 'collector_failed', captcha:error.message === 'captcha_detected'};
    process.exitCode = 1;
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
  process.stdout.write(JSON.stringify(result));
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) await main();
