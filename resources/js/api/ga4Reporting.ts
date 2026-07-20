// resources/js/api/ga4Reporting.ts
import type { ApiClient } from './client';
import type { Ga4ReportResponse } from '../types/api';

export interface Ga4ReportParams {
  start_date: string;
  end_date: string;
}

export async function getGa4Report(
  client: ApiClient,
  params: Ga4ReportParams,
): Promise<Ga4ReportResponse> {
  const search = new URLSearchParams({ start_date: params.start_date, end_date: params.end_date });
  const res = await client(`/api/ga4/report?${search.toString()}`);
  const body = await res.json().catch(() => ({})) as Ga4ReportResponse | { message?: string };
  if (!res.ok) {
    throw new Error('message' in body && body.message ? body.message : 'Failed to load GA4 report.');
  }
  return body as Ga4ReportResponse;
}
