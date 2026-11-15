// resources/js/features/analytics/AnalyticsPage.tsx
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { Alert, Box, Button, Card, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import BarChartRoundedIcon from '@mui/icons-material/BarChartRounded';
import TableRowsRoundedIcon from '@mui/icons-material/TableRowsRounded';
import CalendarMonthRoundedIcon from '@mui/icons-material/CalendarMonthRounded';
import { PageLayout } from '../../components/ui/PageLayout';
import { ModeToggle } from './components/ModeToggle';
import { DayNavigator } from './components/DayNavigator';
import { DateRangePicker } from './components/DateRangePicker';
import { EventCountsTable } from './components/EventCountsTable';
import { EventCountsChart } from './components/EventCountsChart';
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
  const [view, setView] = useState<'table' | 'chart'>('chart');
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
  const isGoogleAds = platform === 'google_ads';
  const deliveryData = isMeta || isGoogleAds ? (data as PlatformDeliveryResponse | undefined) : undefined;

  const counts = eventCountsData?.summary?.counts ?? [];
  const total = eventCountsData?.summary?.total ?? 0;
  const period = data?.summary?.period ?? null;
  const deliveryStats = deliveryData?.summary?.delivery_stats ?? [];
  const deliveryTotals = deliveryData?.summary?.totals ?? { attempted: 0, delivered: 0, failed: 0, pending: 0, other_account: 0, expired: 0 };
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

      <Card variant="outlined" sx={{ mb: 3, p: { xs: 2, sm: 2.5 } }}>
        <Box sx={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 2, flexWrap: 'wrap', mb: 2.5 }}>
          <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center' }}>
            <Box sx={{ width: 42, height: 42, display: 'grid', placeItems: 'center', borderRadius: '13px', bgcolor: '#eef5eb', color: '#638c68' }}><CalendarMonthRoundedIcon fontSize="small" /></Box>
            <Box><Typography sx={{ fontSize: 14, fontWeight: 750 }}>Date & period</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: .2 }}>Choose the activity you want to explore.</Typography></Box>
          </Box>
          <Typography sx={{ fontSize: 11, color: '#819288', bgcolor: '#f5f7f4', px: 1.25, py: .7, borderRadius: '8px' }}>Data retained for {retentionDays} days</Typography>
        </Box>
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <ModeToggle mode={mode} onChange={setMode} />
          {mode === 'single_day' ? (
            <DayNavigator date={date} onChange={setDate} retentionDays={retentionDays} />
          ) : (
            <DateRangePicker startDate={startDate} endDate={endDate} onApply={(s, e) => { setStartDate(s); setEndDate(e); }} retentionDays={retentionDays} />
          )}
          {period && !isFetching && <Typography variant="caption" color="text.secondary">Showing {period.label}{period.days > 1 ? ` (${period.days} days)` : ''}</Typography>}
        </Box>
      </Card>

      {!platform && <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 2, flexWrap: 'wrap', mb: 1.5 }}>
        <Box><Typography variant="h6">Events</Typography><Typography variant="body2" color="text.secondary">Explore activity for your selected period.</Typography></Box>
        <ToggleButtonGroup exclusive value={view} onChange={(_, next: 'table' | 'chart' | null) => { if (next) setView(next); }} size="small" aria-label="Analytics view" sx={{ p: .5, bgcolor: '#eaf0e8', borderRadius: '12px', '& .MuiToggleButtonGroup-grouped': { border: 0, borderRadius: '8px !important', px: 1.5, py: .7, gap: .75, color: '#7b8d7f', textTransform: 'none', fontWeight: 700, fontSize: 12 }, '& .Mui-selected': { color: '#315e46', bgcolor: '#fff !important', boxShadow: '0 1px 4px rgba(29,61,42,.1)' } }}>
          <ToggleButton value="chart" aria-label="Chart view"><BarChartRoundedIcon sx={{ fontSize: 18 }} />Chart</ToggleButton>
          <ToggleButton value="table" aria-label="Table view"><TableRowsRoundedIcon sx={{ fontSize: 18 }} />Table</ToggleButton>
        </ToggleButtonGroup>
      </Box>}
      <Card variant="outlined" sx={{ overflow: 'hidden' }}>
        {!platform && view === 'chart' ? <EventCountsChart counts={counts} total={total} loading={isFetching} /> : isGoogleAds ? (
          <>
            <GoogleAdsAttributionTable summary={eventCountsData?.summary} loading={isFetching} />
            <Typography variant="subtitle1" sx={{ fontWeight: 700, px: 2, pt: 3 }}>
              Conversion uploads to Google Ads
            </Typography>
            <PlatformDeliveryTable
              stats={deliveryStats}
              totals={deliveryTotals}
              loading={isFetching}
              platformLabel="Google Ads"
              integration={deliveryData?.summary?.integration}
              showOtherAccount
              showExpired
            />
          </>
        ) : isMeta ? (
          <PlatformDeliveryTable
            stats={deliveryStats}
            totals={deliveryTotals}
            loading={isFetching}
            platformLabel={PLATFORM_LABELS[platform ?? ''] ?? 'Meta'}
            integration={deliveryData?.summary?.integration}
          />
        ) : (
          <EventCountsTable counts={counts} total={total} loading={isFetching} />
        )}
      </Card>
    </PageLayout>
  );
}
