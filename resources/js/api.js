import { getSessionToken } from '@shopify/app-bridge/utilities';

/**
 * Build an authenticated fetch wrapper that injects a Shopify session token
 * as a Bearer token on every request.
 *
 * App Bridge v3 requires an app instance created via createApp(). When running
 * outside the Shopify iframe (e.g. local dev without a valid host param) the
 * token fetch will throw — the request is sent without a token in that case.
 *
 * @param {object|null} app - App Bridge app instance from createApp()
 * @returns {(url: string, options?: RequestInit) => Promise<Response>}
 */
export function createApiFetch(app) {
    return async function apiFetch(url, options = {}) {
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...options.headers,
        };

        if (app) {
            try {
                const token = await getSessionToken(app);
                headers['Authorization'] = `Bearer ${token}`;
            } catch {
                // App Bridge not fully initialised — fall back to cookie auth.
            }
        }

        return fetch(url, { ...options, headers, credentials: 'include' });
    };
}
