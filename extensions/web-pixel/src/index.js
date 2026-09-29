import { register } from "@shopify/web-pixels-extension";

register(({ analytics, browser, settings, init }) => {
  const API_URL = settings.api_url;
  const TRACKING_SECRET = settings.tracking_secret;
  const SHOP_DOMAIN = init.data.shop.myshopifyDomain;

  const STORAGE_GCLID = "tf_gclid";
  const STORAGE_TTCLID = "tf_ttclid";
  // JSON {fbclid, ts}: ts is the ms timestamp the fbclid was FIRST seen, so the derived
  // fbc ("fb.1.<ts>.<fbclid>") is identical on every event of that click.
  const STORAGE_FBCLID = "tf_fbclid_v2";
  // Meta accepts an fbc for up to 90 days after its creation time.
  const FBCLID_TTL_MS = 90 * 24 * 60 * 60 * 1000;

  let attributionQueue = Promise.resolve();
  // Serialize attribution reads/writes so the landing click is available to the
  // first product event and a later page cannot change an earlier event's ID.
  function enqueueAttribution(url) {
    const result = attributionQueue.then(async () => {
      await captureClickIds(url);
      return getAttribution();
    });
    attributionQueue = result.catch(() => {});
    return result;
  }

  // Path prefixes whose next segment is a token / private identifier
  // (e.g. /checkouts/cn/<token>/thank-you). Everything after them is dropped.
  const SENSITIVE_PATH = /^((?:\/[a-z]{2}(?:-[a-z]{2})?)?\/(?:checkouts|orders|gift_cards|account))(?:\/|$)/i;

  // event_source_url: origin + pathname only. The query string and fragment are never
  // sent (they can carry tokens/click ids), and token-bearing paths are truncated.
  function sourceUrl(href) {
    if (!href) return null;
    try {
      const url = new URL(href);
      if (url.protocol !== "https:") return null;
      const sensitive = SENSITIVE_PATH.exec(url.pathname);
      return url.origin + (sensitive ? sensitive[1] : url.pathname);
    } catch (_) {
      return null;
    }
  }

  // sessionStorage keeps the click for the visit; localStorage (when the sandbox
  // provides it) keeps it across visits until the 90-day fbc validity ends.
  function fbclidStores() {
    return [browser.sessionStorage, browser.localStorage].filter(Boolean);
  }

  // Freshest non-expired {fbclid, ts} record across all stores, or null.
  async function readFbclidRecord() {
    const records = await Promise.all(
      fbclidStores().map(async (store) => {
        try {
          const rec = JSON.parse(await store.getItem(STORAGE_FBCLID));
          const valid =
            rec && typeof rec.fbclid === "string" && rec.fbclid !== "" &&
            Number.isFinite(rec.ts) && Date.now() - rec.ts <= FBCLID_TTL_MS;
          return valid ? rec : null;
        } catch (_) {
          return null;
        }
      })
    );
    return records.reduce((best, rec) => (rec && (!best || rec.ts > best.ts) ? rec : best), null);
  }

  async function writeFbclidRecord(fbclid) {
    const value = JSON.stringify({ fbclid, ts: Date.now() });
    await Promise.all(
      fbclidStores().map(async (store) => {
        try { await store.setItem(STORAGE_FBCLID, value); } catch (_) {}
      })
    );
  }

  function parseFbc(fbc) {
    const match = /^fb\.\d+\.(\d+)\.(.+)$/.exec(fbc || "");
    return match ? { ts: Number(match[1]), fbclid: match[2] } : null;
  }

  // Pick the freshest click: a stored fbclid (seen in the URL) beats an older _fbc
  // cookie, a newer cookie beats an older stored fbclid, and when both describe the
  // same click Meta's own cookie is used as-is.
  function chooseFbc(cookieFbc, record) {
    if (!record) return cookieFbc || null;
    const derived = "fb.1." + record.ts + "." + record.fbclid;
    if (!cookieFbc) return derived;
    const parsed = parseFbc(cookieFbc);
    if (!parsed) return derived;
    if (parsed.fbclid === record.fbclid) return cookieFbc;
    return parsed.ts > record.ts ? cookieFbc : derived;
  }

  async function getAttribution() {
    const [gclid, ttclid, fbclidRecord, gaCookie, fbp, fbc] = await Promise.all([
      browser.sessionStorage.getItem(STORAGE_GCLID),
      browser.sessionStorage.getItem(STORAGE_TTCLID),
      readFbclidRecord(),
      browser.cookie.get("_ga"),
      browser.cookie.get("_fbp"),
      browser.cookie.get("_fbc"),
    ]);

    let gaClientId = null;
    if (gaCookie) {
      const parts = gaCookie.split(".");
      if (parts.length >= 4) {
        gaClientId = parts.slice(2).join(".");
      }
    }

    return {
      gclid: gclid || null,
      ttclid: ttclid || null,
      fbc: chooseFbc(fbc, fbclidRecord),
      fbp: fbp || null,
      ga_client_id: gaClientId,
    };
  }

  async function sendEvent(eventName, payload, event) {
    const href = event?.context?.document?.location?.href;
    const attribution = await enqueueAttribution(href);
    const eventSourceUrl = sourceUrl(href);

    const body = {
      shop_domain: SHOP_DOMAIN,
      tracking_secret: TRACKING_SECRET,
      event: eventName,
      idempotency_key: event?.id || crypto.randomUUID(),
      occurred_at: event?.timestamp || new Date().toISOString(),
      ...(eventSourceUrl ? { event_source_url: eventSourceUrl } : {}),
      ...attribution,
      ...payload,
    };

    fetch(API_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      keepalive: true,
      body: JSON.stringify(body),
    }).catch(() => {});
  }

  async function captureClickIds(url) {
    if (!url) return;
    try {
      const params = new URLSearchParams(new URL(url).search);
      const gclid = params.get("gclid");
      const ttclid = params.get("ttclid");
      const fbclid = params.get("fbclid");
      if (gclid) await browser.sessionStorage.setItem(STORAGE_GCLID, gclid);
      if (ttclid) await browser.sessionStorage.setItem(STORAGE_TTCLID, ttclid);
      if (fbclid) {
        // Same click: keep the original timestamp. New fbclid: replace with a new one.
        const existing = await readFbclidRecord();
        if (!existing || existing.fbclid !== fbclid) await writeFbclidRecord(fbclid);
      }
    } catch (_) {}
  }

  enqueueAttribution(init.context?.document?.location?.href).catch(() => {});

  analytics.subscribe("page_viewed", (event) => {
    return enqueueAttribution(event.context?.document?.location?.href).catch(() => {});
  });

  analytics.subscribe("checkout_completed", (event) => {
    const checkout = event.data?.checkout;
    return sendEvent("purchase", {
      value:
        checkout?.totalPrice?.amount != null
          ? parseFloat(checkout.totalPrice.amount)
          : null,
      currency: checkout?.currencyCode ?? null,
      transaction_id: checkout?.order?.id ?? checkout?.token ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("product_added_to_cart", (event) => {
    const line = event.data?.cartLine;
    return sendEvent("add_to_cart", {
      value:
        line?.cost?.totalAmount?.amount != null
          ? parseFloat(line.cost.totalAmount.amount)
          : null,
      currency: line?.cost?.totalAmount?.currencyCode ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("checkout_started", (event) => {
    const checkout = event.data?.checkout;
    return sendEvent("begin_checkout", {
      value:
        checkout?.totalPrice?.amount != null
          ? parseFloat(checkout.totalPrice.amount)
          : null,
      currency: checkout?.currencyCode ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("payment_info_submitted", (event) => {
    const checkout = event.data?.checkout;
    return sendEvent("add_payment_info", {
      value:
        checkout?.totalPrice?.amount != null
          ? parseFloat(checkout.totalPrice.amount)
          : null,
      currency: checkout?.currencyCode ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("checkout_shipping_info_submitted", (event) => {
    const checkout = event.data?.checkout;
    return sendEvent("add_shipping_info", {
      value:
        checkout?.totalPrice?.amount != null
          ? parseFloat(checkout.totalPrice.amount)
          : null,
      currency: checkout?.currencyCode ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("product_viewed", (event) => {
    const variant = event.data?.productVariant;
    return sendEvent("view_item", {
      value:
        variant?.price?.amount != null
          ? parseFloat(variant.price.amount)
          : null,
      currency: variant?.price?.currencyCode ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("cart_viewed", (event) => {
    const cart = event.data?.cart;
    return sendEvent("view_cart", {
      value:
        cart?.cost?.totalAmount?.amount != null
          ? parseFloat(cart.cost.totalAmount.amount)
          : null,
      currency: cart?.cost?.totalAmount?.currencyCode ?? null,
    }, event).catch(() => {});
  });

  analytics.subscribe("search_submitted", (event) => {
    return sendEvent("search", {}, event).catch(() => {});
  });

  analytics.subscribe("product_removed_from_cart", (event) => {
    const line = event.data?.cartLine;
    return sendEvent("remove_from_cart", {
      value:
        line?.cost?.totalAmount?.amount != null
          ? parseFloat(line.cost.totalAmount.amount)
          : null,
      currency: line?.cost?.totalAmount?.currencyCode ?? null,
    }, event).catch(() => {});
  });
});
