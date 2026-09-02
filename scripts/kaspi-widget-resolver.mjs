// Adapted from autohimiya-laravel's widget resolver: same widget/iframe/popup mechanism,
// with exact identity checks, visible Windows-only launch and fail-closed URL selection.
import { pathToFileURL } from 'node:url';

export function productUrl(value) {
  try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password || url.port
        || !(url.hostname === 'kaspi.kz' || url.hostname.endsWith('.kaspi.kz'))
        || !/^\/shop\/p\/[^/]+-[0-9]+\/?$/.test(url.pathname)) return null;
    return `https://${url.hostname}${url.pathname.replace(/\/$/, '')}/`;
  } catch { return null; }
}

export function resolution(urls, captcha = false) {
  if (captcha) return { status: 'captcha_detected', captcha: true, url: null };
  if (urls.some((url) => !productUrl(url))) return { status: 'invalid_kaspi_url', captcha: false, url: null };
  const distinct = [...new Set(urls.map(productUrl))];
  return { status: distinct.length > 1 ? 'ambiguous_urls' : distinct.length === 1 ? 'resolved' : 'kaspi_url_not_opened',
    captcha: false, url: distinct.length === 1 ? distinct[0] : null };
}

function arg(name) {
  const prefix = `--${name}=`;
  return process.argv.find((value) => value.startsWith(prefix))?.slice(prefix.length);
}

async function captchaDetected(context) {
  for (const page of context.pages()) {
    for (const frame of page.frames()) {
      const detected = await frame.evaluate(() => {
        const text = document.body?.innerText?.toLowerCase() || '';
        return /captcha|капча|verify you are human|подтвердите.*человек|cloudflare|checking your browser|я не робот/.test(text)
          || Boolean(document.querySelector('iframe[src*="captcha"], iframe[src*="challenges.cloudflare.com"], .g-recaptcha, .h-captcha'));
      }).catch(() => false);
      if (detected) return true;
    }
  }
  return false;
}

async function run() {
  if (process.platform !== 'win32' || arg('headless') !== 'false') {
    return { status: 'local_browser_disabled', captcha: false, url: null };
  }
  const url = arg('url');
  const sku = arg('sku');
  const merchant = arg('merchant');
  const city = arg('city');
  let source;
  try { source = new URL(url); } catch { return { status: 'storefront_unavailable', captcha: false, url: null }; }
  if (source.protocol !== 'https:' || source.username || source.password || !sku || !merchant || !city) {
    return { status: 'widget_mismatch', captcha: false, url: null };
  }
  let browser;
  let context;
  let timer;
  const diagnostic = (status) => ({ status, captcha: status === 'captcha_detected', url: null });
  try {
    const { chromium } = await import('playwright');
    browser = await chromium.launch({ headless: false, timeout: 15000 });
    const operation = async () => {
      context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'ru-RU' });
      const page = await context.newPage();
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
      if (await captchaDetected(context)) return diagnostic('captcha_detected');
      if (!response?.ok() || page.url() !== source.href) return diagnostic('storefront_unavailable');
      const widgets = page.locator('.ks-widget');
      if (!await widgets.first().waitFor({ state: 'attached', timeout: 10000 }).then(() => true).catch(() => false)) {
        return diagnostic(await captchaDetected(context) ? 'captcha_detected' : 'widget_not_found');
      }
      let widget;
      for (let i = 0; i < await widgets.count(); i++) {
        const candidate = widgets.nth(i);
        if (await candidate.getAttribute('data-merchant-sku') === sku
            && await candidate.getAttribute('data-merchant-code') === merchant
            && await candidate.getAttribute('data-city') === city) {
          if (widget) return diagnostic('widget_mismatch');
          widget = candidate;
        }
      }
      if (!widget) return diagnostic('widget_mismatch');
      await page.evaluate(() => window.ksWidgetInitializer?.reinit?.());
      if (!await widget.locator('iframe').waitFor({ state: 'attached', timeout: 15000 }).then(() => true).catch(() => false)) {
        return diagnostic(await captchaDetected(context) ? 'captcha_detected' : 'iframe_not_loaded');
      }
      const handle = await widget.locator('iframe').elementHandle();
      const frame = await handle.contentFrame();
      if (!frame) return diagnostic('iframe_not_loaded');
      await frame.waitForLoadState('domcontentloaded', { timeout: 15000 });
      let frameUrl;
      try { frameUrl = new URL(frame.url()); } catch { return diagnostic('iframe_not_loaded'); }
      if (frameUrl.origin !== 'https://kaspi.kz' || frameUrl.pathname !== '/shop/kaspibutton/frame/'
          || frameUrl.searchParams.get('merchantSku') !== sku || frameUrl.searchParams.get('merchantCode') !== merchant
          || frameUrl.searchParams.get('city') !== city) return diagnostic('widget_mismatch');
      // Wait inside the official iframe, not for unrelated links elsewhere in the storefront.
      const ready = await frame.locator('a[href], button, [role="button"]').first()
        .waitFor({ state: 'visible', timeout: 15000 }).then(() => true).catch(() => false);
      if (await captchaDetected(context)) return diagnostic('captcha_detected');
      if (!ready) return diagnostic('iframe_not_loaded');
      const links = await frame.locator('a[href]').evaluateAll((anchors) => anchors.map((a) => a.href)
        .filter((href) => href && !href.startsWith('javascript:') && !href.startsWith('#')));
      if (links.length) return resolution(links, await captchaDetected(context));
      const buttons = frame.locator('button, [role="button"]');
      if (await buttons.count() !== 1) return diagnostic('ambiguous_urls');
      // Watch popup/navigation before clicking the one verified widget control.
      const popup = context.waitForEvent('page', { timeout: 12000 }).catch(() => null);
      const navigation = page.waitForURL((value) => value.href !== source.href, { timeout: 12000 }).catch(() => null);
      await buttons.first().click({ timeout: 5000 });
      const target = await popup;
      await navigation;
      if (target) await target.waitForLoadState('domcontentloaded', { timeout: 12000 });
      if (await captchaDetected(context)) return diagnostic('captcha_detected');
      const urls = context.pages().map((p) => p.url()).filter((value) => value !== source.href && value !== 'about:blank');
      if (frame.url() !== frameUrl.href) urls.push(frame.url());
      return resolution(urls);
    };
    return await Promise.race([operation(), new Promise((resolve) => {
      timer = setTimeout(() => resolve(diagnostic('timeout')), 80000);
    })]);
  } catch (error) {
    const captcha = context ? await captchaDetected(context).catch(() => false) : false;
    if (captcha) return diagnostic('captcha_detected');
    process.stderr.write(`${error?.name === 'TimeoutError' ? 'TimeoutError' : 'BrowserError'}\n`);
    return diagnostic(error?.name === 'TimeoutError' ? 'timeout' : 'browser_error');
  } finally {
    clearTimeout(timer);
    if (browser) await browser.close().catch(() => {});
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const result = await run();
  process.stdout.write(JSON.stringify(result));
  process.exitCode = result.status === 'resolved' ? 0 : 1;
}
