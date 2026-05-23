// resources/js/hooks/useShopify.ts
import { createContext, useContext } from 'react';
import type { ClientApplication } from '../types/shopify';

export const ShopifyAppContext = createContext<ClientApplication | null>(null);

export function useShopify(): ClientApplication | null {
  return useContext(ShopifyAppContext);
}
