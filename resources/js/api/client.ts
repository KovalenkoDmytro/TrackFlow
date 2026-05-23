// resources/js/api/client.ts
import { getSessionToken } from '@shopify/app-bridge/utilities';
import type { ClientApplication } from '../types/shopify';

const BASE_URL = (import.meta.env.DEV
  ? (import.meta.env.VITE_APP_URL ?? 'http://localhost:8000')
  : ''
).replace(/\/$/, '');

export type ApiClient = (url: string, options?: RequestInit) => Promise<Response>;

export function createApiClient(app: ClientApplication | null): ApiClient {
  return async function apiClient(url: string, options: RequestInit = {}): Promise<Response> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers as Record<string, string> | undefined),
    };

    if (app) {
      try {
        const token = await getSessionToken(app);
        headers['Authorization'] = `Bearer ${token}`;
      } catch {
        // App Bridge not fully initialised — fall back to cookie auth.
      }
    }

    const resolvedUrl = url.startsWith('/') ? `${BASE_URL}${url}` : url;
    return fetch(resolvedUrl, { ...options, headers, credentials: 'include' });
  };
}
