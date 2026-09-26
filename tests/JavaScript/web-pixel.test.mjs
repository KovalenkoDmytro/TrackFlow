import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../extensions/web-pixel/src/index.js', import.meta.url), 'utf8').replace(/^import .*;\n/, '');
function setup(url) {
  const handlers = {}, storage = new Map(), sent = [];
  vm.runInNewContext(source, {
    register: fn => fn({
      analytics: { subscribe: (name, fn) => handlers[name] = fn },
      browser: {
        sessionStorage: {
          getItem: async key => storage.get(key),
          setItem: async (key, value) => { await new Promise(resolve => setTimeout(resolve, 5)); storage.set(key, value); },
        },
        cookie: { get: async () => undefined },
      },
      settings: { api_url: 'https://example.test/track', tracking_secret: 'test' },
      init: { data: { shop: { myshopifyDomain: 'shop.myshopify.com' } }, context: { document: { location: { href: url } } } },
    }),
    URL, URLSearchParams, Date, crypto: { randomUUID: () => 'fallback-id' },
    fetch: async (_url, options) => { sent.push(JSON.parse(options.body)); },
  });
  return { handlers, sent };
}
function event(id, url) {
  return { id, timestamp: '2026-09-25T10:00:00Z', context: { document: { location: { href: url } } }, data: {} };
}
test('first product event waits for landing click storage; Shopify identity and time remain stable', async () => {
  const { handlers, sent } = setup('https://store.test/?gclid=LandingClick');
  await handlers.product_viewed(event('shopify-event', 'https://store.test/product'));
  await handlers.product_viewed(event('shopify-event', 'https://store.test/product'));
  assert.equal(sent[0].gclid, 'LandingClick');
  assert.equal(sent[0].idempotency_key, sent[1].idempotency_key);
  assert.equal(sent[0].occurred_at, '2026-09-25T10:00:00Z');
});
test('organic visits are not given a Google click ID', async () => {
  const { handlers, sent } = setup('https://store.test/?srsltid=organic');
  await handlers.search_submitted(event('organic', 'https://store.test/search'));
  assert.equal(sent[0].gclid, null);
});
test('later click does not contaminate earlier event attribution', async () => {
  const { handlers, sent } = setup('https://store.test/?gclid=First');
  const earlier = handlers.product_viewed(event('earlier', 'https://store.test/product'));
  const later = handlers.product_viewed(event('later', 'https://store.test/?gclid=Second'));
  await Promise.all([earlier, later]);
  assert.equal(sent.find(row => row.idempotency_key === 'earlier').gclid, 'First');
  assert.equal(sent.find(row => row.idempotency_key === 'later').gclid, 'Second');
});
