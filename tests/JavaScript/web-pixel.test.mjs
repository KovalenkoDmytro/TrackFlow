import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../extensions/web-pixel/src/index.js', import.meta.url), 'utf8').replace(/^import .*;\n/, '');
const DAY = 24 * 60 * 60 * 1000;
// Options: cookies (name -> value), session/local (Map, shareable across setups to
// simulate a later page or visit), noLocal (sandbox without localStorage), clock ({ now }).
function setup(url, { cookies = {}, session = new Map(), local = new Map(), noLocal = false, clock = { now: 1_000_000 } } = {}) {
  const handlers = {}, sent = [];
  const store = map => ({
    getItem: async key => map.get(key),
    setItem: async (key, value) => { await new Promise(resolve => setTimeout(resolve, 5)); map.set(key, value); },
  });
  class FakeDate extends Date { static now() { return clock.now; } }
  vm.runInNewContext(source, {
    register: fn => fn({
      analytics: { subscribe: (name, fn) => handlers[name] = fn },
      browser: {
        sessionStorage: store(session),
        ...(noLocal ? {} : { localStorage: store(local) }),
        cookie: { get: async name => cookies[name] },
      },
      settings: { api_url: 'https://example.test/track', tracking_secret: 'test' },
      init: { data: { shop: { myshopifyDomain: 'shop.myshopify.com' } }, context: { document: { location: { href: url } } } },
    }),
    URL, URLSearchParams, Date: FakeDate, JSON, Number, crypto: { randomUUID: () => 'fallback-id' },
    fetch: async (_url, options) => { sent.push(JSON.parse(options.body)); },
  });
  return { handlers, sent, session, local, clock };
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

test('fbc uses the first-seen timestamp of the fbclid and is stable across events', async () => {
  const { handlers, sent, clock } = setup('https://store.test/?fbclid=AbC');
  await handlers.product_viewed(event('e1', 'https://store.test/product'));
  clock.now += 60_000;
  await handlers.product_added_to_cart(event('e2', 'https://store.test/cart'));
  assert.equal(sent[0].fbc, 'fb.1.1000000.AbC');
  assert.equal(sent[1].fbc, 'fb.1.1000000.AbC');
});
test('a new fbclid replaces the stored one with a new timestamp', async () => {
  const { handlers, sent, clock } = setup('https://store.test/?fbclid=Old');
  await handlers.product_viewed(event('e1', 'https://store.test/product'));
  clock.now += 5_000;
  await handlers.product_viewed(event('e2', 'https://store.test/?fbclid=New'));
  await handlers.product_viewed(event('e3', 'https://store.test/product'));
  assert.equal(sent[0].fbc, 'fb.1.1000000.Old');
  assert.equal(sent[1].fbc, 'fb.1.1005000.New');
  assert.equal(sent[2].fbc, 'fb.1.1005000.New');
});
test('fbc persists across pixel restarts through localStorage, and is dropped after 90 days', async () => {
  const first = setup('https://store.test/?fbclid=Keep');
  await first.handlers.product_viewed(event('e1', 'https://store.test/product'));
  // New visit: fresh sessionStorage, same localStorage, no fbclid in the URL.
  const later = setup('https://store.test/', { local: first.local, clock: { now: 1_000_000 + 30 * DAY } });
  await later.handlers.product_viewed(event('e2', 'https://store.test/product'));
  assert.equal(later.sent[0].fbc, 'fb.1.1000000.Keep');
  const expired = setup('https://store.test/', { local: first.local, clock: { now: 1_000_000 + 91 * DAY } });
  await expired.handlers.product_viewed(event('e3', 'https://store.test/product'));
  assert.equal(expired.sent[0].fbc, null);
});
test('falls back to sessionStorage when the sandbox has no localStorage', async () => {
  const { handlers, sent } = setup('https://store.test/?fbclid=Sess', { noLocal: true });
  await handlers.product_viewed(event('e1', 'https://store.test/product'));
  assert.equal(sent[0].fbc, 'fb.1.1000000.Sess');
});
test('a fbclid seen now beats an older _fbc cookie; a newer cookie beats an older fbclid; same click uses the cookie', async () => {
  const older = setup('https://store.test/?fbclid=Fresh', { cookies: { _fbc: 'fb.1.500.Stale' } });
  await older.handlers.product_viewed(event('e1', 'https://store.test/product'));
  assert.equal(older.sent[0].fbc, 'fb.1.1000000.Fresh');
  const newer = setup('https://store.test/?fbclid=Fresh', { cookies: { _fbc: 'fb.1.2000000.Newer' } });
  await newer.handlers.product_viewed(event('e2', 'https://store.test/product'));
  assert.equal(newer.sent[0].fbc, 'fb.1.2000000.Newer');
  const same = setup('https://store.test/?fbclid=Same', { cookies: { _fbc: 'fb.1.999999.Same' } });
  await same.handlers.product_viewed(event('e3', 'https://store.test/product'));
  assert.equal(same.sent[0].fbc, 'fb.1.999999.Same');
});
test('uses the _fbc cookie when no fbclid was ever seen, else null', async () => {
  const withCookie = setup('https://store.test/', { cookies: { _fbc: 'fb.1.123.C' } });
  await withCookie.handlers.search_submitted(event('e1', 'https://store.test/search'));
  assert.equal(withCookie.sent[0].fbc, 'fb.1.123.C');
  const none = setup('https://store.test/');
  await none.handlers.search_submitted(event('e2', 'https://store.test/search'));
  assert.equal(none.sent[0].fbc, null);
});
test('event_source_url is origin + pathname only (no query, no fragment)', async () => {
  const { handlers, sent } = setup('https://store.test/');
  await handlers.product_viewed(event('e1', 'https://store.test/products/x?fbclid=abc&token=secret#frag'));
  assert.equal(sent[0].event_source_url, 'https://store.test/products/x');
});
test('event_source_url drops token-bearing checkout paths and non-https or missing URLs', async () => {
  const { handlers, sent } = setup('https://store.test/');
  await handlers.checkout_completed(event('c1', 'https://store.test/checkouts/cn/SECRETTOKEN/en-ca/thank-you?key=1'));
  await handlers.checkout_started(event('c2', 'https://store.test/en-ca/checkouts/cn/SECRETTOKEN'));
  await handlers.search_submitted(event('c3', 'http://store.test/search'));
  await handlers.cart_viewed({ id: 'c4', timestamp: '2026-09-25T10:00:00Z', data: {} });
  assert.equal(sent[0].event_source_url, 'https://store.test/checkouts');
  assert.equal(sent[1].event_source_url, 'https://store.test/en-ca/checkouts');
  assert.equal('event_source_url' in sent[2], false);
  assert.equal('event_source_url' in sent[3], false);
});
