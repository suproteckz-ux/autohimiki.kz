import { test } from 'node:test';
import assert from 'node:assert/strict';
import { productUrl, resolution, waitForWidgetFrame } from './kaspi-widget-resolver.mjs';

test('only HTTPS Kaspi product links are accepted', () => {
  for (const url of ['http://kaspi.kz/shop/p/item-123/', 'https://kaspi.kz.evil.test/shop/p/item-123/',
    'https://kaspi.kz@evil.test/shop/p/item-123/', 'https://evil.test/?next=https://kaspi.kz/shop/p/item-123/',
    'https://kaspi.kz/login', 'https://kaspi.kz/shop/', 'https://kaspi.kz/shop/p/captcha/']) assert.equal(productUrl(url), null);
  assert.equal(productUrl('https://kaspi.kz/shop/p/item-123/?c=750000000'), 'https://kaspi.kz/shop/p/item-123/');
});
test('CAPTCHA wins even with a valid product link', () => {
  assert.equal(resolution(['https://kaspi.kz/shop/p/item-123/'], true).status, 'captcha_detected');
});
test('different product links are ambiguous', () => {
  assert.equal(resolution(['https://kaspi.kz/shop/p/item-123/', 'https://kaspi.kz/shop/p/other-456/']).status, 'ambiguous_urls');
});
test('query variants of the same product are not ambiguous', () => {
  assert.equal(resolution(['https://kaspi.kz/shop/p/item-123/?c=1', 'https://kaspi.kz/shop/p/item-123/?c=2']).status, 'resolved');
});
test('missing or invalid links do not resolve', () => {
  assert.equal(resolution([]).status, 'kaspi_url_not_opened');
  assert.equal(resolution(['https://example.com/shop/p/item-123/']).status, 'invalid_kaspi_url');
});

function delayedWidget({widgetAt=0, scriptAt=0, iframeAt=0, frameAt=0, navigationAt=0, controlsAt=0, mismatch=false} = {}) {
  let time = 0;
  let reinitializations = 0;
  const identity = {sku:'00000000680', merchant:'Avtoximiya', city:'750000000'};
  const frameUrl = 'https://kaspi.kz/shop/kaspibutton/frame/?merchantSku=00000000680&merchantCode=Avtoximiya&city=750000000';
  const frame = {
    url: () => time < navigationAt ? 'about:blank' : frameUrl,
    locator: () => ({first: () => ({isVisible: async () => time >= controlsAt})}),
  };
  const iframe = {
    count: async () => time >= iframeAt ? 1 : 0,
    elementHandle: async () => ({contentFrame: async () => time >= frameAt ? frame : null, dispose: async () => {}}),
  };
  const widget = {
    getAttribute: async name => ({'data-merchant-sku':mismatch ? 'other' : identity.sku,
      'data-merchant-code':identity.merchant, 'data-city':identity.city})[name],
    locator: () => iframe,
  };
  const page = {
    locator: () => ({count: async () => time >= widgetAt ? 1 : 0, nth: () => widget}),
    evaluate: async () => {
      if (time < scriptAt) return false;
      reinitializations++;
      return true;
    },
  };
  const options = {now: () => time, pause: async ms => {time += ms;}};
  return {page, identity, options, elapsed: () => time, inits: () => reinitializations};
}

test('delayed widget, script, iframe attachment, frame and controls share a bounded wait', async () => {
  const fake = delayedWidget({widgetAt:12000, scriptAt:20000, iframeAt:30000, frameAt:35000, navigationAt:40000, controlsAt:45000});
  const result = await waitForWidgetFrame(fake.page, fake.identity, fake.options);
  assert.equal(result.status, 'ready');
  assert.equal(fake.elapsed(), 45000);
  assert.equal(fake.inits(), 1, 'do not reinitialize an iframe while it loads');
});

test('missing widget consumes the whole deadline and reports widget_not_found', async () => {
  const fake = delayedWidget({widgetAt:Infinity});
  assert.equal((await waitForWidgetFrame(fake.page, fake.identity, fake.options)).status, 'widget_not_found');
  assert.equal(fake.elapsed(), 60000);
});

test('late or absent external script/iframe does not cause an early close', async () => {
  const fake = delayedWidget({scriptAt:Infinity, iframeAt:Infinity});
  assert.equal((await waitForWidgetFrame(fake.page, fake.identity, fake.options)).status, 'iframe_not_loaded');
  assert.equal(fake.elapsed(), 60000);
  assert.equal(fake.inits(), 0);
});

test('about:blank frame is transient, not an identity mismatch', async () => {
  const fake = delayedWidget({navigationAt:25000, controlsAt:26000});
  assert.equal((await waitForWidgetFrame(fake.page, fake.identity, fake.options)).status, 'ready');
  assert.equal(fake.elapsed(), 26000);
});

test('CAPTCHA interrupts retries and a wrong SKU never becomes ready', async () => {
  const fake = delayedWidget({widgetAt:Infinity});
  assert.equal((await waitForWidgetFrame(fake.page, fake.identity, {...fake.options, captcha: async () => fake.elapsed() >= 2000})).status, 'captcha_detected');
  assert.equal(fake.elapsed(), 2000);
  const wrong = delayedWidget({mismatch:true});
  assert.equal((await waitForWidgetFrame(wrong.page, wrong.identity, wrong.options)).status, 'widget_mismatch');
  assert.equal(wrong.elapsed(), 60000);
});