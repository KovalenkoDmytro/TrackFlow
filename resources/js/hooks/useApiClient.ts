// resources/js/hooks/useApiClient.ts
import { useMemo } from 'react';
import { createApiClient, type ApiClient } from '../api/client';
import { useShopify } from './useShopify';

export function useApiClient(): ApiClient {
  const app = useShopify();
  return useMemo(() => createApiClient(app), [app]);
}
