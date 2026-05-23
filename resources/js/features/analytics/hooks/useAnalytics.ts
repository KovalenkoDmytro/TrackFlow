// resources/js/features/analytics/hooks/useAnalytics.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../hooks/useApiClient';
import { getAnalytics } from '../../../api/analytics';
import type { AnalyticsParams } from '../../../types/api';

export function useAnalytics(params: AnalyticsParams) {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['analytics', params],
    queryFn: () => getAnalytics(apiClient, params),
  });
}
