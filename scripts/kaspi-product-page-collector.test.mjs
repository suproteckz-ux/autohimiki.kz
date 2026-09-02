import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pageReason, expectedUrl } from './kaspi-product-page-collector.mjs';
test('collector rejects captcha, empty html, wrong product, HTTP errors and oversized html', () => {
  const good = {url:expectedUrl,status:200,html:'<body><h1>Product</h1></body>',captcha:false};
  assert.equal(pageReason(good), null);
  assert.equal(pageReason({...good,captcha:true}), 'captcha_detected');
  assert.equal(pageReason({...good,html:''}), 'collector_empty_html');
  assert.equal(pageReason({...good,url:expectedUrl.replace('142775620','123')}), 'wrong_product');
  assert.equal(pageReason({...good,status:403}), 'collector_http_failed');
  assert.equal(pageReason({...good,html:good.html+'x'.repeat(4000000)}), 'collector_html_too_large');
});
