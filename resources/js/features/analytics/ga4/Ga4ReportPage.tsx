// resources/js/features/analytics/ga4/Ga4ReportPage.tsx
import { useState } from 'react';
import { Alert, Box, Card, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { DateRangePicker } from '../components/DateRangePicker';
import { useShopGa4Property } from './hooks/useShopGa4Property';
import { useGa4Report } from './hooks/useGa4Report';

const METRIC_LABELS = ['Sessions', 'Active Users', 'Conversions', 'Total Revenue'];

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

function daysAgoString(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().slice(0, 10);
}

export function Ga4ReportPage() {
  const [startDate, setStartDate] = useState(daysAgoString(28));
  const [endDate, setEndDate] = useState(todayString());

  const propertyQuery = useShopGa4Property();
  const hasProperty = Boolean(propertyQuery.query.data?.setting?.active);

  const reportQuery = useGa4Report({ start_date: startDate, end_date: endDate }, hasProperty);

  if (propertyQuery.query.isLoading) return <LoadingState />;

  if (!hasProperty) {
    return (
      <PageLayout title="GA4 Report" backTo="/analytics">
        <Alert severity="info">
          No GA4 property is configured for this shop yet. Add your GA4 Property ID on the{' '}
          <a href="/settings/ga4">GA4 settings page</a> to see reporting data here.
        </Alert>
      </PageLayout>
    );
  }

  const row = reportQuery.data?.report?.rows?.[0];
  const values = row?.metricValues?.map((m) => m.value) ?? [];

  return (
    <PageLayout title="GA4 Report" backTo="/analytics">
      <Card variant="outlined" sx={{ mb: 3, p: 2 }}>
        <DateRangePicker
          startDate={startDate}
          endDate={endDate}
          onApply={(s, e) => { setStartDate(s); setEndDate(e); }}
        />
      </Card>

      {reportQuery.error && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {reportQuery.error.message}
        </Alert>
      )}

      <Card variant="outlined">
        {reportQuery.isFetching ? (
          <LoadingState />
        ) : (
          <Table>
            <TableHead>
              <TableRow>
                {METRIC_LABELS.map((label) => (
                  <TableCell key={label}>{label}</TableCell>
                ))}
              </TableRow>
            </TableHead>
            <TableBody>
              <TableRow>
                {METRIC_LABELS.map((label, i) => (
                  <TableCell key={label}>{values[i] ?? '—'}</TableCell>
                ))}
              </TableRow>
            </TableBody>
          </Table>
        )}
        {!reportQuery.isFetching && values.length === 0 && !reportQuery.error && (
          <Box sx={{ p: 3 }}>
            <Typography variant="body2" color="text.secondary">
              No data for the selected date range.
            </Typography>
          </Box>
        )}
      </Card>
    </PageLayout>
  );
}
