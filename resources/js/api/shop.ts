// resources/js/api/shop.ts
import type { ApiClient } from './client';
import type { ShopStatusResponse, TogglePixelResponse } from '../types/api';

export async function getShopStatus(client: ApiClient): Promise<ShopStatusResponse> {
  const res = await client('/api/shop-status');
  if (!res.ok) throw new Error('Failed to load shop status');
  return res.json() as Promise<ShopStatusResponse>;
}

export async function togglePixel(client: ApiClient, enabled: boolean): Promise<TogglePixelResponse> {
  const res = await client('/api/pixel', {
    method: 'PUT',
    body: JSON.stringify({ enabled }),
  });
  const body = await res.json() as TogglePixelResponse | { message?: string };
  if (!res.ok) {
    throw new Error(('message' in body ? body.message : undefined) ?? 'Failed to update pixel status.');
  }
  return body as TogglePixelResponse;
}
