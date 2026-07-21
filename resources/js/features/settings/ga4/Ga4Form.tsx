// resources/js/features/settings/ga4/Ga4Form.tsx
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Alert, Box, Button, Card, CardContent, Chip, TextField, Typography } from '@mui/material';
import { DisabledApiPanel } from './components/DisabledApiPanel';
import { ScopeErrorPanel } from './components/ScopeErrorPanel';
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
const DRAFT_FIELDS = ['property_id'] as const;
type DraftField = (typeof DRAFT_FIELDS)[number];

function loadGa4Draft(): Partial<Pick<Ga4FormData, DraftField>> {
  try {
    return JSON.parse(localStorage.getItem(DRAFT_KEY) ?? '{}') as Partial<Pick<Ga4FormData, DraftField>>;
  } catch {
    return {};
  }
}

function saveGa4Draft(values: Pick<Ga4FormData, DraftField>): void {
  localStorage.setItem(DRAFT_KEY, JSON.stringify(values));
}

function isScopeError(message: string): boolean {
  const lower = message.toLowerCase();
  return lower.includes('insufficient') || lower.includes('scopes');
}

function isApiDisabledError(message: string): boolean {
  const lower = message.toLowerCase();
  return lower.includes('analyticsadmin') || lower.includes('analytics admin api') || lower.includes('has not been used');
}

/**
 * Forces a top-level browser navigation instead of a same-frame link.
 *
 * The Shopify embedded app runs inside an iframe; Google's OAuth consent
 * screen refuses to render inside a frame, so the "Connect with Google"
 * button must break out to the top-level window rather than navigating
 * within the iframe.
 */
function navigateTopLevel(path: string): void {
  window.top!.location.href = `${window.location.origin}${path}`;
}

interface Ga4FormProps {
  onDisconnect: () => void;
  isDisconnecting: boolean;
}

export function Ga4Form({ onDisconnect, isDisconnecting }: Ga4FormProps) {
  const { query, saveMutation } = useGa4Settings();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [googleStatusMessage, setGoogleStatusMessage] = useState<{ severity: 'success' | 'error'; text: string } | null>(null);

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
      if (name && (DRAFT_FIELDS as readonly string[]).includes(name)) {
        saveGa4Draft({
          property_id: value.property_id ?? '',
        });
      }
    });
    return () => subscription.unsubscribe();
  }, [form]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const google = params.get('google');
    const googleError = params.get('google_error');

    if (google || googleError) {
      setGoogleStatusMessage(
        googleError
          ? { severity: 'error', text: googleError }
          : { severity: 'success', text: 'Google account connected successfully.' },
      );

      params.delete('google');
      params.delete('google_error');
      const newSearch = params.toString();
      window.history.replaceState({}, '', window.location.pathname + (newSearch ? `?${newSearch}` : ''));
    }
  }, []);

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
  const hasOauthConnection = query.data?.has_oauth_connection ?? false;

  return (
    <>
      {successMessage && (
        <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccessMessage(null)}>
          {successMessage}
        </Alert>
      )}

      {googleStatusMessage && (
        <Alert severity={googleStatusMessage.severity} sx={{ mb: 3 }} onClose={() => setGoogleStatusMessage(null)}>
          {googleStatusMessage.text}
        </Alert>
      )}

      {apiError && (
        <Box sx={{ mb: 3 }}>
          <Alert severity="error" onClose={() => saveMutation.reset()}>
            {apiError}
          </Alert>
          {isScopeError(apiError) && <ScopeErrorPanel />}
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
              Optional: connect your Google account to automatically create Key Events in your GA4 property.
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
            <Box sx={{ display: 'flex', gap: 1.5, alignItems: 'center' }}>
              <Button
                variant="outlined"
                onClick={() => navigateTopLevel('/settings/ga4/google/start')}
              >
                {hasOauthConnection ? 'Reconnect with Google' : 'Connect with Google'}
              </Button>
              <Chip
                label={hasOauthConnection ? 'Connected' : 'Not connected'}
                color={hasOauthConnection ? 'success' : 'default'}
                size="small"
              />
            </Box>
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
