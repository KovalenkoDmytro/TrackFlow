// resources/js/features/analytics/ga4/hooks/useShopGa4Property.ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import { getShopGa4Property, saveShopGa4Property } from '../../../../api/settings';

const QUERY_KEY = ['settings', 'ga4-property'] as const;

export function useShopGa4Property() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => getShopGa4Property(apiClient),
  });

  const saveMutation = useMutation({
    mutationFn: (propertyId: string) => saveShopGa4Property(apiClient, propertyId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  return { query, saveMutation };
}
