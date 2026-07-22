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

export interface PlatformDeliveryStat {
  event: string;
  label: string;
  attempted: number;
  delivered: number;
  failed: number;
  pending: number;
  last_error: string | null;
}

export interface PlatformDeliveryTotals {
  attempted: number;
  delivered: number;
  failed: number;
  pending: number;
}

export interface PlatformDeliverySummary {
  period: AnalyticsPeriod | null;
  delivery_stats: PlatformDeliveryStat[];
  totals: PlatformDeliveryTotals;
}

export interface PlatformDeliveryResponse {
  summary: PlatformDeliverySummary;
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
  has_developer_token: boolean;
  has_oauth_client_id: boolean;
  has_oauth_client_secret: boolean;
  has_oauth_refresh_token: boolean;
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
  has_oauth_client_id: boolean;
  has_oauth_client_secret: boolean;
  has_oauth_refresh_token: boolean;
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

export interface MetaCredentials {
  pixel_id: string;
  test_event_code: string;
  access_token: string;
  has_access_token: boolean;
}

export interface MetaIntegration {
  active: boolean;
}

export interface MetaMapping {
  id: number;
  event: string;
  active: boolean;
  external_action_id: string | null;
}

export interface MetaSettingsResponse {
  integration: MetaIntegration | null;
  mappings: MetaMapping[];
  credentials: MetaCredentials | null;
}

export interface MetaSaveResponse {
  integration: MetaIntegration | null;
}

export interface MetaFormData {
  pixel_id: string;
  access_token: string;
  test_event_code: string;
}

export class ValidationError extends Error {
  constructor(public readonly fieldErrors: Record<string, string[]>) {
    super('Validation failed');
    this.name = 'ValidationError';
  }
}
