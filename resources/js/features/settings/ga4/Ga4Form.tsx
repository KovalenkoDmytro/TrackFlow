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
  oauth_client_id: z.string(),
  oauth_client_secret: z.string(),
  oauth_refresh_token: z.string(),
});

const EMPTY_FORM: Ga4FormData = {
  measurement_id: '',
  api_secret: '',
  property_id: '',
  oauth_client_id: '',
  oauth_client_secret: '',
  oauth_refresh_token: '',
};

const DRAFT_KEY = 'trackflow_ga4_draft';
const DRAFT_FIELDS = ['property_id', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'] as const;
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
        // OAuth secrets are write-only — the API never returns the stored value,
        // so these always start empty (a local unsaved draft may still prefill them).
        oauth_client_id: draft.oauth_client_id || '',
        oauth_client_secret: draft.oauth_client_secret || '',
        oauth_refresh_token: draft.oauth_refresh_token || '',
      });
    }
  }, [query.data, form]);

  const credentials = query.data?.credentials;
  const hasOauthClientId = credentials?.has_oauth_client_id ?? false;
  const hasOauthClientSecret = credentials?.has_oauth_client_secret ?? false;
  const hasOauthRefreshToken = credentials?.has_oauth_refresh_token ?? false;

  useEffect(() => {
    const subscription = form.watch((value, { name }) => {
      if (name && (DRAFT_FIELDS as readonly string[]).includes(name)) {
        saveGa4Draft({
          property_id: value.property_id ?? '',
          oauth_client_id: value.oauth_client_id ?? '',
          oauth_client_secret: value.oauth_client_secret ?? '',
          oauth_refresh_token: value.oauth_refresh_token ?? '',
        });
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
              Optional: provide these to automatically create Key Events in your GA4 property.
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
            <TextField
              {...form.register('oauth_client_id')}
              type="password"
              label={
                <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.75 }}>
                  OAuth Client ID
                  {hasOauthClientId && <Chip label="Set" color="success" size="small" sx={{ height: 18 }} />}
                </Box>
              }
              helperText={
                form.formState.errors.oauth_client_id?.message ??
                (hasOauthClientId
                  ? 'Already set — leave blank to keep the current value, or enter a new one to replace it.'
                  : 'From Google Cloud Console → APIs & Services → Credentials')
              }
              error={!!form.formState.errors.oauth_client_id}
              fullWidth
            />
            <TextField
              {...form.register('oauth_client_secret')}
              type="password"
              label={
                <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.75 }}>
                  OAuth Client Secret
                  {hasOauthClientSecret && <Chip label="Set" color="success" size="small" sx={{ height: 18 }} />}
                </Box>
              }
              helperText={
                form.formState.errors.oauth_client_secret?.message ??
                (hasOauthClientSecret
                  ? 'Already set — leave blank to keep the current value, or enter a new one to replace it.'
                  : 'From the same Google Cloud Console OAuth Client as above')
              }
              error={!!form.formState.errors.oauth_client_secret}
              fullWidth
            />
            <TextField
              {...form.register('oauth_refresh_token')}
              type="password"
              label={
                <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.75 }}>
                  OAuth Refresh Token
                  {hasOauthRefreshToken && <Chip label="Set" color="success" size="small" sx={{ height: 18 }} />}
                </Box>
              }
              helperText={
                form.formState.errors.oauth_refresh_token?.message ??
                (hasOauthRefreshToken
                  ? 'Already set — leave blank to keep the current value, or enter a new one to replace it.'
                  : 'From Google OAuth Playground: use your own credentials above, authorize with the analytics.readonly + analytics.edit scopes, then exchange for tokens')
              }
              error={!!form.formState.errors.oauth_refresh_token}
              fullWidth
              multiline
              rows={3}
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
