// resources/js/features/settings/ga4/hooks/useGa4Settings.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import {
  getGa4Settings,
  saveGa4Settings,
  deleteGa4Settings,
} from '../../../../api/settings';
import type { Ga4FormData } from '../../../../types/api';

const QUERY_KEY = ['settings', 'ga4'] as const;

export function useGa4Settings() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => getGa4Settings(apiClient),
  });

  const saveMutation = useMutation({
    mutationFn: (data: Ga4FormData) => saveGa4Settings(apiClient, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => deleteGa4Settings(apiClient),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  return { query, saveMutation, disconnectMutation };
}
