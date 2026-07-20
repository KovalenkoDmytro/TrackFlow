// resources/js/features/analytics/ga4/hooks/useGa4Report.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import { getGa4Report, type Ga4ReportParams } from '../../../../api/ga4Reporting';

export function useGa4Report(params: Ga4ReportParams, enabled: boolean) {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['ga4-report', params],
    queryFn: () => getGa4Report(apiClient, params),
    enabled,
    retry: false,
  });
}
