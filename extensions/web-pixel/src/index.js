import { register } from "@shopify/web-pixels-extension";

register(({ analytics, browser, settings, init }) => {
  const API_URL = settings.api_url;
  const TRACKING_SECRET = settings.tracking_secret;
  const SHOP_DOMAIN = init.data.shop.myshopifyDomain;

  const STORAGE_GCLID = "tf_gclid";
  const STORAGE_TTCLID = "tf_ttclid";
  const STORAGE_FBCLID = "tf_fbclid";

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

  async function getAttribution() {
    const [gclid, ttclid, fbclid, gaCookie, fbp, fbc] = await Promise.all([
      browser.sessionStorage.getItem(STORAGE_GCLID),
      browser.sessionStorage.getItem(STORAGE_TTCLID),
      browser.sessionStorage.getItem(STORAGE_FBCLID),
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
      fbc: fbc || (fbclid ? "fb.1." + Date.now() + "." + fbclid : null),
      fbp: fbp || null,
      ga_client_id: gaClientId,
    };
  }

  async function sendEvent(eventName, payload, event) {
    const attribution = await enqueueAttribution(event?.context?.document?.location?.href);

    const body = {
      shop_domain: SHOP_DOMAIN,
      tracking_secret: TRACKING_SECRET,
      event: eventName,
      idempotency_key: event?.id || crypto.randomUUID(),
      occurred_at: event?.timestamp || new Date().toISOString(),
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
      if (fbclid) await browser.sessionStorage.setItem(STORAGE_FBCLID, fbclid);
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
