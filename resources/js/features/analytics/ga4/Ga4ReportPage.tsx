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
  Link,
  Paper,
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

function Ga4SetupInstructions() {
  return (
    <Card variant="outlined" sx={{ mb: 3 }}>
      <CardContent>
        <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
          How to connect your GA4 property
        </Typography>
        <Typography variant="body2" sx={{ mb: 1.5 }}>
          TrackFlow reads your reporting data through a single Google account that our team
          manages — you don&apos;t need to sign in with Google or set up your own OAuth connection.
          Just follow the steps below.
        </Typography>

        <Box component="ol" sx={{ pl: 2.5, m: 0 }}>
          <Box component="li" sx={{ mb: 1.5 }}>
            <Typography variant="body2">
              <strong>Find your GA4 Property ID.</strong> Go to{' '}
              <Link href="https://analytics.google.com" target="_blank" rel="noopener">
                Google Analytics
              </Link>{' '}
              → Admin (gear icon) → make sure the correct GA4 property is selected → Property
              Settings → copy the <strong>Property ID</strong> (a plain number, e.g.{' '}
              <code>123456789</code>). This is different from the Measurement ID (
              <code>G-XXXXXXXXXX</code>), which you don&apos;t need here.
            </Typography>
          </Box>
          <Box component="li" sx={{ mb: 1.5 }}>
            <Typography variant="body2">
              <strong>Grant access to TrackFlow&apos;s connected Google account.</strong> In
              Google Analytics: Admin → Property Access Management → click the{' '}
              <strong>+</strong> button → Add users → enter TrackFlow&apos;s connected Google
              account email with the <strong>Viewer</strong> role → Add. Contact TrackFlow
              support if you need the email address of our connected Google account.
            </Typography>
          </Box>
          <Box component="li">
            <Typography variant="body2">
              <strong>Enter your Property ID below</strong> and save — TrackFlow will verify
              access automatically.
            </Typography>
          </Box>
        </Box>

        <Paper variant="outlined" sx={{ mt: 2, p: 1.5, borderColor: 'divider' }}>
          <Typography variant="caption" sx={{ fontWeight: 700, display: 'block', mb: 0.5 }}>
            Troubleshooting
          </Typography>
          <Typography variant="caption" color="text.secondary" component="div">
            &quot;This GA4 property could not be verified&quot; means either the Property ID is
            wrong or the shared account hasn&apos;t been granted Viewer access yet (see step 2
            above). &quot;GA4 reporting is busy refreshing credentials&quot; is transient — just
            try again in a moment.
          </Typography>
        </Paper>

        <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
          Note: this Property ID is separate from the Measurement ID and API Secret configured
          on the GA4 settings page — those are used to send conversion events, while this one is
          only used to display reports here.
        </Typography>
      </CardContent>
    </Card>
  );
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

      <Ga4SetupInstructions />

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
