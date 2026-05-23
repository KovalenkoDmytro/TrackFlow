// resources/js/features/home/hooks/useShopStatus.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../hooks/useApiClient';
import { getShopStatus } from '../../../api/shop';

export function useShopStatus() {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['shop-status'],
    queryFn: () => getShopStatus(apiClient),
  });
}
