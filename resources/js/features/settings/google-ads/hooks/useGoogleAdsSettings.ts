// resources/js/features/settings/google-ads/hooks/useGoogleAdsSettings.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import {
  getGoogleAdsSettings,
  saveGoogleAdsSettings,
  deleteGoogleAdsSettings,
} from '../../../../api/settings';
import type { GoogleAdsFormData } from '../../../../types/api';

const QUERY_KEY = ['settings', 'google-ads'] as const;

export function useGoogleAdsSettings() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => getGoogleAdsSettings(apiClient),
  });

  const saveMutation = useMutation({
    mutationFn: (data: GoogleAdsFormData) => saveGoogleAdsSettings(apiClient, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => deleteGoogleAdsSettings(apiClient),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  return { query, saveMutation, disconnectMutation };
}
