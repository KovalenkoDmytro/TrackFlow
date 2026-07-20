// resources/js/api/googleOperator.ts
import type { ApiClient } from './client';
import type { GoogleOperatorStatusResponse } from '../types/api';

export async function getGoogleOperatorStatus(client: ApiClient): Promise<GoogleOperatorStatusResponse> {
  const res = await client('/api/operator/google-status');
  if (!res.ok) throw new Error('Failed to load Google connection status.');
  return res.json() as Promise<GoogleOperatorStatusResponse>;
}
