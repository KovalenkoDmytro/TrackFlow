// resources/js/features/settings/google/hooks/useGoogleOperator.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import { getGoogleOperatorStatus } from '../../../../api/googleOperator';

export function useGoogleOperatorStatus() {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['operator', 'google-status'],
    queryFn: () => getGoogleOperatorStatus(apiClient),
  });
}
