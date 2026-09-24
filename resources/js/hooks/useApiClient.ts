// resources/js/hooks/useApiClient.ts
import { useMemo } from 'react';
import { createApiClient, type ApiClient } from '../api/client';

export function useApiClient(): ApiClient {
  return useMemo(() => createApiClient(), []);
}
