// resources/js/api/client.ts
import type { ShopifyGlobal } from '../types/shopify';

const BASE_URL = (import.meta.env.DEV
  ? (import.meta.env.VITE_APP_URL ?? 'http://localhost:8000')
  : ''
).replace(/\/$/, '');

const RETRY_HEADER = 'X-Shopify-Retry-Invalid-Session-Request';

interface SessionErrorBody {
  message: string;
  code: 'session_token_missing' | 'session_token_invalid' | 'session_token_expired' | 'shop_not_installed';
}

export type ApiClient = (url: string, options?: RequestInit) => Promise<Response>;

function getShopify(): ShopifyGlobal | undefined {
  return (globalThis as { shopify?: ShopifyGlobal }).shopify;
}

function redirectToAuthenticate(): void {
  const { shop, host } = getShopify()?.config ?? {};
  const params = new URLSearchParams({ shop: shop ?? '', host: host ?? '' });
  window.top!.location.href = `/authenticate?${params.toString()}`;
}

async function buildHeaders(options: RequestInit): Promise<Record<string, string>> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(options.headers as Record<string, string> | undefined),
  };

  const token = await getShopify()?.idToken();
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  return headers;
}

export function createApiClient(): ApiClient {
  return async function apiClient(url: string, options: RequestInit = {}): Promise<Response> {
    const resolvedUrl = url.startsWith('/') ? `${BASE_URL}${url}` : url;
    const headers = await buildHeaders(options);
    const response = await fetch(resolvedUrl, { ...options, headers, credentials: 'omit' });

    if (response.status !== 401) return response;

    const body = (await response.clone().json()) as SessionErrorBody;

    if (body.code === 'shop_not_installed') {
      redirectToAuthenticate();
      return response;
    }

    if (response.headers.get(RETRY_HEADER) === '1') {
      const retryHeaders = await buildHeaders(options);
      return fetch(resolvedUrl, { ...options, headers: retryHeaders, credentials: 'omit' });
    }

    return response;
  };
}
