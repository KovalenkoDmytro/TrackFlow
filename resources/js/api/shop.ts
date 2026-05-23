// resources/js/api/shop.ts
import type { ApiClient } from './client';
import type { ShopStatusResponse } from '../types/api';

export async function getShopStatus(client: ApiClient): Promise<ShopStatusResponse> {
  const res = await client('/api/shop-status');
  if (!res.ok) throw new Error('Failed to load shop status');
  return res.json() as Promise<ShopStatusResponse>;
}
