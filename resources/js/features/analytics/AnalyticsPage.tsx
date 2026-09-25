// resources/js/features/analytics/AnalyticsPage.tsx
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Alert, Box, Button, Card, Typography } from '@mui/material';
import { PageLayout } from '../../components/ui/PageLayout';
import { ModeToggle } from './components/ModeToggle';
import { DayNavigator } from './components/DayNavigator';
import { DateRangePicker } from './components/DateRangePicker';
import { EventCountsTable } from './components/EventCountsTable';
import { GoogleAdsAttributionTable } from './components/GoogleAdsAttributionTable';
import { PlatformDeliveryTable } from './components/PlatformDeliveryTable';
import { useAnalytics } from './hooks/useAnalytics';
import type { AnalyticsMode, AnalyticsParams, AnalyticsResponse, PlatformDeliveryResponse } from '../../types/api';

// Fallback used only until the first API response arrives; the API's `meta.retention_days`
// (sourced from config('tracking.retention_days')) is the source of truth thereafter.
const DEFAULT_RETENTION_DAYS = 90;

const PLATFORM_LABELS: Record<string, string> = {
  google_ads: 'Google Ads',
  meta: 'Meta',
  tiktok: 'TikTok',
  ga4: 'GA4',
};

function todayString(): string {
  const date = new Date();
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function AnalyticsPage() {
  const { platform } = useParams<{ platform?: string }>();
  const today = todayString();

  const [mode, setMode] = useState<AnalyticsMode>('single_day');
  const [date, setDate] = useState(today);
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);

  const params: AnalyticsParams =
    mode === 'single_day'
      ? { mode, date, platform }
      : { mode, start_date: startDate, end_date: endDate, platform };

  const { data, isFetching, error } = useAnalytics(params);
  const isMeta = platform === 'meta';

  const eventCountsData = !isMeta ? (data as AnalyticsResponse | undefined) : undefined;
  const deliveryData = isMeta ? (data as PlatformDeliveryResponse | undefined) : undefined;

  const counts = eventCountsData?.summary?.counts ?? [];
  const total = eventCountsData?.summary?.total ?? 0;
  const period = data?.summary?.period ?? null;
  const deliveryStats = deliveryData?.summary?.delivery_stats ?? [];
  const deliveryTotals = deliveryData?.summary?.totals ?? { attempted: 0, delivered: 0, failed: 0, pending: 0 };
  const retentionDays = data?.meta?.retention_days ?? DEFAULT_RETENTION_DAYS;

  const title = platform === 'google_ads' ? 'Google Ads click-matched events' : platform ? `${PLATFORM_LABELS[platform] ?? platform} Events` : 'Analytics';

  const actions = platform ? (
    <Button variant="text" size="small" href="/analytics">
      All Events
    </Button>
  ) : undefined;

  return (
    <PageLayout title={title} backTo="/" actions={actions}>
      {error && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {error.message}
        </Alert>
      )}

      <Card variant="outlined" sx={{ mb: 3, p: { xs: 1.5, sm: 2.5 } }}>
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <Alert severity="info">
            Tracking data is stored for {retentionDays} days. Dates outside this period are unavailable.
          </Alert>
          <ModeToggle mode={mode} onChange={setMode} />

          {mode === 'single_day' ? (
            <DayNavigator date={date} onChange={setDate} retentionDays={retentionDays} />
          ) : (
            <DateRangePicker
              startDate={startDate}
              endDate={endDate}
              onApply={(s, e) => { setStartDate(s); setEndDate(e); }}
              retentionDays={retentionDays}
            />
          )}

          {period && !isFetching && (
            <Typography variant="caption" color="text.secondary">
              Showing {period.label}{period.days > 1 ? ` (${period.days} days)` : ''}
            </Typography>
          )}
        </Box>
      </Card>

      <Card variant="outlined" sx={{ overflow: 'hidden' }}>
        {platform === 'google_ads' ? (
          <GoogleAdsAttributionTable summary={eventCountsData?.summary} loading={isFetching} />
        ) : isMeta ? (
          <PlatformDeliveryTable stats={deliveryStats} totals={deliveryTotals} loading={isFetching} />
        ) : (
          <EventCountsTable counts={counts} total={total} loading={isFetching} />
        )}
      </Card>
    </PageLayout>
  );
}
