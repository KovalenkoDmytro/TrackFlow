// resources/js/features/analytics/AnalyticsPage.tsx
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Alert, Box, Button, Card, Typography } from '@mui/material';
import { PageLayout } from '../../components/ui/PageLayout';
import { ModeToggle } from './components/ModeToggle';
import { DayNavigator } from './components/DayNavigator';
import { DateRangePicker } from './components/DateRangePicker';
import { EventCountsTable } from './components/EventCountsTable';
import { PlatformDeliveryTable } from './components/PlatformDeliveryTable';
import { useAnalytics } from './hooks/useAnalytics';
import type { AnalyticsMode, AnalyticsParams, AnalyticsResponse, PlatformDeliveryResponse } from '../../types/api';

const PLATFORM_LABELS: Record<string, string> = {
  google_ads: 'Google Ads',
  meta: 'Meta',
  tiktok: 'TikTok',
  ga4: 'GA4',
};

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
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

  const title = platform ? `${PLATFORM_LABELS[platform] ?? platform} Events` : 'Analytics';

  const actions = platform ? (
    <Button variant="text" size="small" href="/analytics">
      All Events
    </Button>
  ) : undefined;

  return (
    <PageLayout title={title} maxWidth={800} backTo="/" actions={actions}>
      {error && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {error.message}
        </Alert>
      )}

      <Card variant="outlined" sx={{ mb: 3, p: 2 }}>
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <ModeToggle mode={mode} onChange={setMode} />

          {mode === 'single_day' ? (
            <DayNavigator date={date} onChange={setDate} />
          ) : (
            <DateRangePicker
              startDate={startDate}
              endDate={endDate}
              onApply={(s, e) => { setStartDate(s); setEndDate(e); }}
            />
          )}

          {period && !isFetching && (
            <Typography variant="caption" color="text.secondary">
              Showing {period.label}{period.days > 1 ? ` (${period.days} days)` : ''}
            </Typography>
          )}
        </Box>
      </Card>

      <Card variant="outlined">
        {isMeta ? (
          <PlatformDeliveryTable stats={deliveryStats} totals={deliveryTotals} loading={isFetching} />
        ) : (
          <EventCountsTable counts={counts} total={total} loading={isFetching} />
        )}
      </Card>
    </PageLayout>
  );
}
