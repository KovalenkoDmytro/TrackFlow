// resources/js/types/api.ts

export interface ShopRecord {
  shopify_pixel_id: string | null;
}

export interface ShopStatusResponse {
  shop: ShopRecord;
  integrations: Record<string, boolean>;
}

export type AnalyticsMode = 'single_day' | 'range';

export interface AnalyticsParams {
  mode: AnalyticsMode;
  date?: string;
  start_date?: string;
  end_date?: string;
}

export interface EventCount {
  event: string;
  label: string;
  count: number;
}

export interface AnalyticsPeriod {
  label: string;
  days: number;
}

export interface AnalyticsSummary {
  counts: EventCount[];
  total: number;
  period: AnalyticsPeriod | null;
}

export interface AnalyticsResponse {
  summary: AnalyticsSummary;
}

export interface GoogleAdsOAuth {
  client_id: string;
  client_secret: string;
  refresh_token: string;
}

export interface GoogleAdsCredentials {
  customer_id: string;
  mcc_id: string;
  developer_token: string;
  oauth: GoogleAdsOAuth;
}

export interface GoogleAdsIntegration {
  active: boolean;
}

export interface GoogleAdsMapping {
  id: number;
  event: string;
  active: boolean;
  external_action_id: string | null;
}

export interface GoogleAdsSettingsResponse {
  integration: GoogleAdsIntegration | null;
  mappings: GoogleAdsMapping[];
  credentials: GoogleAdsCredentials | null;
}

export interface GoogleAdsSaveResponse {
  integration: GoogleAdsIntegration | null;
}

export interface GoogleAdsFormData {
  customer_id: string;
  mcc_id: string;
  developer_token: string;
  oauth_client_id: string;
  oauth_client_secret: string;
  oauth_refresh_token: string;
}

export interface Ga4Credentials {
  measurement_id: string;
  api_secret: string;
  property_id: string;
  oauth_client_id: string;
  oauth_client_secret: string;
  oauth_refresh_token: string;
}

export interface Ga4SettingsResponse {
  connected: boolean;
  credentials: Ga4Credentials | null;
}

export interface Ga4FormData {
  measurement_id: string;
  api_secret: string;
  property_id: string;
  oauth_client_id: string;
  oauth_client_secret: string;
  oauth_refresh_token: string;
}

export class ValidationError extends Error {
  constructor(public readonly fieldErrors: Record<string, string[]>) {
    super('Validation failed');
    this.name = 'ValidationError';
  }
}
