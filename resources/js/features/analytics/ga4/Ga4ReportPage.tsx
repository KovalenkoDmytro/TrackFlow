// resources/js/features/analytics/ga4/Ga4ReportPage.tsx
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { DateRangePicker } from '../components/DateRangePicker';
import { useShopGa4Property } from './hooks/useShopGa4Property';
import { useGa4Report } from './hooks/useGa4Report';
import { ValidationError } from '../../../types/api';

const propertyIdSchema = z.object({
  property_id: z
    .string()
    .min(1, 'Required')
    .regex(/^\d+$/, 'Must be a numeric GA4 Property ID'),
});

type PropertyIdFormData = z.infer<typeof propertyIdSchema>;

const METRIC_LABELS = ['Sessions', 'Active Users', 'Conversions', 'Total Revenue'];

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

function daysAgoString(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().slice(0, 10);
}

interface Ga4PropertySetupFormProps {
  saveMutation: ReturnType<typeof useShopGa4Property>['saveMutation'];
}

function Ga4PropertySetupForm({ saveMutation }: Ga4PropertySetupFormProps) {
  const form = useForm<PropertyIdFormData>({
    resolver: zodResolver(propertyIdSchema),
    defaultValues: { property_id: '' },
  });

  async function onSubmit(data: PropertyIdFormData) {
    try {
      await saveMutation.mutateAsync(data.property_id);
    } catch (err) {
      if (err instanceof ValidationError) {
        Object.entries(err.fieldErrors).forEach(([field, messages]) => {
          form.setError(field as keyof PropertyIdFormData, { message: messages[0] });
        });
      }
    }
  }

  const apiError = saveMutation.error instanceof Error && !(saveMutation.error instanceof ValidationError)
    ? saveMutation.error.message
    : null;

  return (
    <PageLayout title="GA4 Report" backTo="/analytics">
      <Alert severity="info" sx={{ mb: 3 }}>
        No GA4 property is configured for this shop yet. Enter your GA4 Property ID below to start
        seeing reporting data here.
      </Alert>

      {apiError && (
        <Alert severity="error" sx={{ mb: 3 }} onClose={() => saveMutation.reset()}>
          {apiError}
        </Alert>
      )}

      <Card variant="outlined">
        <CardContent>
          <Box
            component="form"
            onSubmit={form.handleSubmit(onSubmit)}
            sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}
          >
            <TextField
              {...form.register('property_id')}
              label="GA4 Property ID"
              placeholder="123456789"
              helperText={
                form.formState.errors.property_id?.message ??
                'Numeric GA4 Property ID (Admin → Property Settings). This uses TrackFlow\'s '
                  + 'shared Google connection to read reporting data — no OAuth setup needed on your end.'
              }
              error={!!form.formState.errors.property_id}
              fullWidth
              required
            />
            <Box>
              <Button type="submit" variant="contained" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Verifying…' : 'Save & Verify'}
              </Button>
            </Box>
          </Box>
        </CardContent>
      </Card>
    </PageLayout>
  );
}

export function Ga4ReportPage() {
  const [startDate, setStartDate] = useState(daysAgoString(28));
  const [endDate, setEndDate] = useState(todayString());

  const propertyQuery = useShopGa4Property();
  const hasProperty = Boolean(propertyQuery.query.data?.setting?.active);

  const reportQuery = useGa4Report({ start_date: startDate, end_date: endDate }, hasProperty);

  if (propertyQuery.query.isLoading) return <LoadingState />;

  if (!hasProperty) {
    return <Ga4PropertySetupForm saveMutation={propertyQuery.saveMutation} />;
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
