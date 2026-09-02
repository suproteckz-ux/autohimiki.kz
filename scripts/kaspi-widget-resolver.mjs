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

// Shared by both commands. Poll the entire asynchronous widget lifecycle under one deadline.
export async function waitForWidgetFrame(page, identity, {
  timeoutMs = 60000, pollMs = 500, now = Date.now,
  pause = ms => new Promise(resolve => setTimeout(resolve, ms)),
  captcha = async () => false,
} = {}) {
  const deadline = now() + timeoutMs;
  let reason = 'widget_not_found';
  let initialized = false;
  while (now() < deadline) {
    if (await captcha()) return { status: 'captcha_detected' };
    const widgets = page.locator('.ks-widget');
    const count = await widgets.count();
    let widget;
    for (let i = 0; i < count; i++) {
      const candidate = widgets.nth(i);
      if (await candidate.getAttribute('data-merchant-sku', {timeout:1000}) === identity.sku
          && await candidate.getAttribute('data-merchant-code', {timeout:1000}) === identity.merchant
          && await candidate.getAttribute('data-city', {timeout:1000}) === identity.city) {
        if (widget) return { status: 'widget_mismatch' };
        widget = candidate;
      }
    }
    reason = widget ? 'iframe_not_loaded' : count ? 'widget_mismatch' : 'widget_not_found';
    if (widget) {
      const iframes = widget.locator('iframe');
      const frameCount = await iframes.count();
      if (frameCount > 1) return { status: 'widget_mismatch' };
      if (!frameCount && !initialized) {
        // A missing initializer is transient. Retry until the external script is available;
        // once initialized, do not repeatedly reinit and destroy an iframe still loading.
        initialized = await page.evaluate(() => {
          if (typeof window.ksWidgetInitializer?.reinit !== 'function') return false;
          window.ksWidgetInitializer.reinit();
          return true;
        });
      }
      if (frameCount === 1) {
        const handle = await iframes.elementHandle({timeout:1000});
        const frame = handle ? await handle.contentFrame() : null;
        await handle?.dispose();
        if (frame && frame.url() && frame.url() !== 'about:blank') {
          let frameUrl;
          try { frameUrl = new URL(frame.url()); } catch { return { status: 'widget_mismatch' }; }
          if (frameUrl.origin !== 'https://kaspi.kz' || frameUrl.pathname !== '/shop/kaspibutton/frame/'
              || frameUrl.searchParams.get('merchantSku') !== identity.sku
              || frameUrl.searchParams.get('merchantCode') !== identity.merchant
              || frameUrl.searchParams.get('city') !== identity.city) return { status: 'widget_mismatch' };
          if (await frame.locator('a[href], button, [role="button"]').first().isVisible()) {
            if (await captcha()) return { status: 'captcha_detected' };
            return { status: 'ready', frame, frameUrl };
          }
        }
      }
    }
    const remaining = deadline - now();
    if (remaining > 0) await pause(Math.min(pollMs, remaining));
  }
  return { status: reason };
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
      const waiting = await waitForWidgetFrame(page, {sku, merchant, city}, {
        captcha: () => captchaDetected(context),
      });
      if (waiting.status !== 'ready') return diagnostic(waiting.status);
      const {frame, frameUrl} = waiting;
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
      timer = setTimeout(() => resolve(diagnostic('timeout')), 120000);
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
