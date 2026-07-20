// resources/js/features/settings/ga4/Ga4Form.tsx
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Alert, Box, Button, Card, CardContent, TextField, Typography } from '@mui/material';
import { DisabledApiPanel } from './components/DisabledApiPanel';
import { useGa4Settings } from './hooks/useGa4Settings';
import { ValidationError } from '../../../types/api';
import type { Ga4FormData } from '../../../types/api';

const schema = z.object({
  measurement_id: z.string().min(1, 'Required'),
  api_secret: z.string().min(1, 'Required'),
  property_id: z.string(),
});

const EMPTY_FORM: Ga4FormData = {
  measurement_id: '',
  api_secret: '',
  property_id: '',
};

const DRAFT_KEY = 'trackflow_ga4_draft';

function loadGa4Draft(): Partial<Pick<Ga4FormData, 'property_id'>> {
  try {
    return JSON.parse(localStorage.getItem(DRAFT_KEY) ?? '{}') as Partial<Pick<Ga4FormData, 'property_id'>>;
  } catch {
    return {};
  }
}

function saveGa4Draft(values: Pick<Ga4FormData, 'property_id'>): void {
  localStorage.setItem(DRAFT_KEY, JSON.stringify(values));
}

function isApiDisabledError(message: string): boolean {
  const lower = message.toLowerCase();
  return lower.includes('analyticsadmin') || lower.includes('analytics admin api') || lower.includes('has not been used');
}

interface Ga4FormProps {
  onDisconnect: () => void;
  isDisconnecting: boolean;
}

export function Ga4Form({ onDisconnect, isDisconnecting }: Ga4FormProps) {
  const { query, saveMutation } = useGa4Settings();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const form = useForm<Ga4FormData>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_FORM,
  });

  useEffect(() => {
    const draft = loadGa4Draft();
    const creds = query.data?.credentials;
    if (creds || Object.keys(draft).length > 0) {
      form.reset({
        measurement_id: creds?.measurement_id ?? '',
        api_secret: creds?.api_secret ?? '',
        property_id: creds?.property_id || draft.property_id || '',
      });
    }
  }, [query.data, form]);

  useEffect(() => {
    const subscription = form.watch((value, { name }) => {
      if (name === 'property_id') {
        saveGa4Draft({ property_id: value.property_id ?? '' });
      }
    });
    return () => subscription.unsubscribe();
  }, [form]);

  async function onSubmit(data: Ga4FormData) {
    setSuccessMessage(null);
    try {
      await saveMutation.mutateAsync(data);
      setSuccessMessage('GA4 connected. Event mappings are being configured in the background.');
      localStorage.removeItem(DRAFT_KEY);
    } catch (err) {
      if (err instanceof ValidationError) {
        Object.entries(err.fieldErrors).forEach(([field, messages]) => {
          form.setError(field as keyof Ga4FormData, { message: messages[0] });
        });
      }
    }
  }

  const apiError = saveMutation.error instanceof Error && !(saveMutation.error instanceof ValidationError)
    ? saveMutation.error.message
    : null;

  const isConnected = query.data?.connected ?? false;

  return (
    <>
      {successMessage && (
        <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccessMessage(null)}>
          {successMessage}
        </Alert>
      )}

      {apiError && (
        <Box sx={{ mb: 3 }}>
          <Alert severity="error" onClose={() => saveMutation.reset()}>
            {apiError}
          </Alert>
          {isApiDisabledError(apiError) && <DisabledApiPanel />}
        </Box>
      )}

      <Card variant="outlined">
        <CardContent>
          <Box
            component="form"
            onSubmit={form.handleSubmit(onSubmit)}
            sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}
          >
            <TextField
              {...form.register('measurement_id')}
              label="Measurement ID"
              placeholder="G-XXXXXXXXXX"
              helperText={
                form.formState.errors.measurement_id?.message ??
                'Your GA4 property Measurement ID (e.g. G-XXXXXXXXXX)'
              }
              error={!!form.formState.errors.measurement_id}
              fullWidth
              required
            />
            <TextField
              {...form.register('api_secret')}
              label="API Secret"
              helperText={
                form.formState.errors.api_secret?.message ??
                'Create one in GA4: Admin → Data Streams → your stream → Measurement Protocol API secrets'
              }
              error={!!form.formState.errors.api_secret}
              fullWidth
              required
            />
            <Typography variant="body2" color="text.secondary">
              Optional: provide your Property ID to automatically create Key Events in your GA4
              property. This uses TrackFlow&apos;s shared Google connection — no OAuth setup needed
              on your end.
            </Typography>
            <TextField
              {...form.register('property_id')}
              label="Property ID"
              helperText={
                form.formState.errors.property_id?.message ??
                'Numeric GA4 Property ID (Admin → Property Settings).'
              }
              error={!!form.formState.errors.property_id}
              fullWidth
            />
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
              <Button type="submit" variant="contained" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save & Connect'}
              </Button>
              {isConnected && (
                <Button
                  variant="outlined"
                  color="error"
                  size="small"
                  onClick={onDisconnect}
                  disabled={isDisconnecting}
                >
                  {isDisconnecting ? 'Disconnecting…' : 'Disconnect'}
                </Button>
              )}
            </Box>
          </Box>
        </CardContent>
      </Card>
    </>
  );
}
