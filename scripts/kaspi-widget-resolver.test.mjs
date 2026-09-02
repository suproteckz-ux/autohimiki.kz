import { test } from 'node:test';
import assert from 'node:assert/strict';
import { productUrl, resolution } from './kaspi-widget-resolver.mjs';

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
