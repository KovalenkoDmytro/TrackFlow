// resources/js/types/shopify.ts
import type { ClientApplication } from '@shopify/app-bridge';

export type { ClientApplication };

declare global {
  interface Window {
    __SHOPIFY_API_KEY__?: string;
  }
}
