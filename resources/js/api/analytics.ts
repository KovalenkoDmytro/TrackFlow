// resources/js/api/analytics.ts
import type { ApiClient } from './client';
import type { AnalyticsParams, AnalyticsResponse } from '../types/api';

export async function getAnalytics(
  client: ApiClient,
  params: AnalyticsParams,
): Promise<AnalyticsResponse> {
  const search = new URLSearchParams();
  search.set('mode', params.mode);
  if (params.mode === 'single_day') {
    search.set('date', params.date ?? '');
  } else {
    search.set('start_date', params.start_date ?? '');
    search.set('end_date', params.end_date ?? '');
  }

  if (params.platform) {
    search.set('platform', params.platform);
  }

  const res = await client(`/api/analytics?${search.toString()}`);
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { message?: string };
    throw new Error(body.message ?? 'Failed to load analytics');
  }
  return res.json() as Promise<AnalyticsResponse>;
}
