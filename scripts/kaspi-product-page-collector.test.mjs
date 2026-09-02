import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pageReason as checkPage, validProductUrl } from './kaspi-product-page-collector.mjs';
const expectedUrl = 'https://kaspi.kz/shop/p/mitsuji-ochistitel-universal-nyi-mtz4002-0-65-l-142775620/';
const pageReason = data => checkPage(data, expectedUrl);
test('collector rejects captcha, empty html, wrong product, HTTP errors and oversized html', () => {
  const good = {url:expectedUrl,status:200,html:'<body><h1>Product</h1></body>',captcha:false};
  assert.equal(pageReason(good), null);
  assert.equal(pageReason({...good,captcha:true}), 'captcha_detected');
  assert.equal(pageReason({...good,html:''}), 'collector_empty_html');
  assert.equal(pageReason({...good,url:expectedUrl.replace('142775620','123')}), 'wrong_product');
  assert.equal(pageReason({...good,status:403}), 'collector_http_failed');
  assert.equal(pageReason({...good,html:good.html+'x'.repeat(4000000)}), 'collector_html_too_large');
});

test('collector supports arbitrary resolved product while rejecting redirects to another product', () => {
  const url = 'https://kaspi.kz/shop/p/other-product-987654/';
  const data = {url,status:200,html:'<body>Other product</body>',captcha:false};
  assert.equal(validProductUrl(url), true);
  assert.equal(checkPage(data, url), null);
  assert.equal(checkPage({...data,url:expectedUrl}, url), 'wrong_product');
  for (const bad of ['http://kaspi.kz/shop/p/a-1/', 'https://kaspi.kz.evil.test/shop/p/a-1/', 'https://kaspi.kz/shop/p/a-1/?secret=1']) {
    assert.equal(validProductUrl(bad), false);
  }
});
