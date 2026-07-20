// resources/js/types/api.ts

export interface ShopRecord {
  shopify_pixel_id: string | null;
  pixel_enabled: boolean;
}

export interface ShopStatusResponse {
  shop: ShopRecord;
  integrations: Record<string, boolean>;
}

export interface TogglePixelResponse {
  pixel_enabled: boolean;
  shopify_pixel_id: string | null;
}

export type AnalyticsMode = 'single_day' | 'range';

export interface AnalyticsParams {
  mode: AnalyticsMode;
  date?: string;
  start_date?: string;
  end_date?: string;
  platform?: string;
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
}

export interface Ga4SettingsResponse {
  connected: boolean;
  credentials: Ga4Credentials | null;
}

export interface Ga4FormData {
  measurement_id: string;
  api_secret: string;
  property_id: string;
}

// GA4 Data API reporting (read-only) — separate from the Measurement
// Protocol credentials above. Each shop only assigns its own GA4
// property_id; the actual Data API calls run through a single shared
// operator-connected Google account (see App\Services\Ga4TokenProvider).
export interface ShopGa4Property {
  property_id: string;
  property_display_name: string | null;
  property_timezone: string | null;
  property_currency: string | null;
  active: boolean;
  last_verified_at: string | null;
}

export interface ShopGa4PropertyResponse {
  setting: ShopGa4Property | null;
}

export interface Ga4ReportMetricValue {
  value: string;
}

export interface Ga4ReportRow {
  metricValues?: Ga4ReportMetricValue[];
}

export interface Ga4ReportData {
  rows?: Ga4ReportRow[];
  metricHeaders?: { name: string }[];
}

export interface Ga4ReportResponse {
  report: Ga4ReportData;
}

export interface GoogleOperatorStatusResponse {
  connected: boolean;
  connected_at: string | null;
  is_operator: boolean;
}

export class ValidationError extends Error {
  constructor(public readonly fieldErrors: Record<string, string[]>) {
    super('Validation failed');
    this.name = 'ValidationError';
  }
}
