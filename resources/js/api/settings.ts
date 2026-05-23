// resources/js/api/settings.ts
import type { ApiClient } from './client';
import type {
  GoogleAdsSettingsResponse,
  GoogleAdsFormData,
  GoogleAdsSaveResponse,
  Ga4SettingsResponse,
  Ga4FormData,
} from '../types/api';
import { ValidationError } from '../types/api';

export async function getGoogleAdsSettings(
  client: ApiClient,
): Promise<GoogleAdsSettingsResponse> {
  const res = await client('/api/settings/google-ads');
  if (!res.ok) throw new Error('Failed to load settings');
  return res.json() as Promise<GoogleAdsSettingsResponse>;
}

export async function saveGoogleAdsSettings(
  client: ApiClient,
  data: GoogleAdsFormData,
): Promise<GoogleAdsSaveResponse> {
  const res = await client('/api/settings/google-ads', {
    method: 'POST',
    body: JSON.stringify(data),
  });
  const body = await res.json() as GoogleAdsSaveResponse | { errors?: Record<string, string[]>; error?: string };
  if (res.status === 422 && 'errors' in body && body['errors']) {
    throw new ValidationError(body['errors']);
  }
  if (!res.ok) {
    throw new Error(('error' in body ? body['error'] : undefined) ?? 'An unexpected error occurred.');
  }
  return body as GoogleAdsSaveResponse;
}

export async function deleteGoogleAdsSettings(client: ApiClient): Promise<void> {
  const res = await client('/api/settings/google-ads', { method: 'DELETE' });
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { error?: string };
    throw new Error(body.error ?? 'Failed to disconnect.');
  }
}

export async function getGa4Settings(client: ApiClient): Promise<Ga4SettingsResponse> {
  const res = await client('/api/settings/ga4');
  if (!res.ok) throw new Error('Failed to load settings');
  return res.json() as Promise<Ga4SettingsResponse>;
}

export async function saveGa4Settings(
  client: ApiClient,
  data: Ga4FormData,
): Promise<void> {
  const res = await client('/api/settings/ga4', {
    method: 'POST',
    body: JSON.stringify(data),
  });
  const body = await res.json() as Record<string, unknown>;
  if (res.status === 422 && body['errors']) {
    throw new ValidationError(body['errors'] as Record<string, string[]>);
  }
  if (!res.ok) {
    throw new Error((body['message'] as string | undefined) ?? 'An unexpected error occurred.');
  }
}

export async function deleteGa4Settings(client: ApiClient): Promise<void> {
  const res = await client('/api/settings/ga4', { method: 'DELETE' });
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { message?: string };
    throw new Error(body.message ?? 'Failed to disconnect.');
  }
}
