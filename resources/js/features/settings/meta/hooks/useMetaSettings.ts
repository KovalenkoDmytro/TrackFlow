// resources/js/features/settings/meta/hooks/useMetaSettings.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import {
  getMetaSettings,
  saveMetaSettings,
  deleteMetaSettings,
} from '../../../../api/settings';
import type { MetaFormData } from '../../../../types/api';

const QUERY_KEY = ['settings', 'meta'] as const;

export function useMetaSettings() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => getMetaSettings(apiClient),
  });

  const saveMutation = useMutation({
    mutationFn: (data: MetaFormData) => saveMetaSettings(apiClient, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => deleteMetaSettings(apiClient),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  return { query, saveMutation, disconnectMutation };
}
