// resources/js/features/home/hooks/usePixelToggle.ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../hooks/useApiClient';
import { togglePixel } from '../../../api/shop';

const SHOP_STATUS_QUERY_KEY = ['shop-status'] as const;

export function usePixelToggle() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (enabled: boolean) => togglePixel(apiClient, enabled),
    // Refetch from the backend rather than trusting the mutation response alone,
    // so the UI always reflects the real Shopify-side pixel state.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: SHOP_STATUS_QUERY_KEY }),
  });
}
