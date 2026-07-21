// resources/js/features/settings/google-ads/GoogleAdsForm.tsx
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { PermissionTroubleshootingPanel } from './components/PermissionTroubleshootingPanel';
import { useGoogleAdsSettings } from './hooks/useGoogleAdsSettings';
import { ValidationError } from '../../../types/api';
import type { GoogleAdsFormData } from '../../../types/api';

const schema = z.object({
  customer_id: z.string().min(1, 'Required'),
  mcc_id: z.string(),
  developer_token: z.string(),
  oauth_client_id: z.string(),
  oauth_client_secret: z.string(),
  oauth_refresh_token: z.string(),
});

const EMPTY_FORM: GoogleAdsFormData = {
  customer_id: '',
  mcc_id: '',
  developer_token: '',
  oauth_client_id: '',
  oauth_client_secret: '',
  oauth_refresh_token: '',
};

function isPermissionError(message: string): boolean {
  const lower = message.toLowerCase();
  return message.includes('403') || lower.includes('does not have permission');
}

interface GoogleAdsFormProps {
  onDisconnect: () => void;
  isDisconnecting: boolean;
}

export function GoogleAdsForm({ onDisconnect, isDisconnecting }: GoogleAdsFormProps) {
  const { query, saveMutation } = useGoogleAdsSettings();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const form = useForm<GoogleAdsFormData>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_FORM,
  });

  useEffect(() => {
    const creds = query.data?.credentials;
    if (creds) {
      form.reset({
        customer_id: creds.customer_id ?? '',
        mcc_id: creds.mcc_id ?? '',
        // Developer token and OAuth secrets are write-only — the API never returns
        // the stored value, so these always start empty.
        developer_token: '',
        oauth_client_id: '',
        oauth_client_secret: '',
        oauth_refresh_token: '',
      });
    }
  }, [query.data, form]);

  const credentials = query.data?.credentials;
  const hasDeveloperToken = credentials?.has_developer_token ?? false;
  const hasOauthClientId = credentials?.has_oauth_client_id ?? false;
  const hasOauthClientSecret = credentials?.has_oauth_client_secret ?? false;
  const hasOauthRefreshToken = credentials?.has_oauth_refresh_token ?? false;

  async function onSubmit(data: GoogleAdsFormData) {
    setSuccessMessage(null);
    try {
      await saveMutation.mutateAsync(data);
      setSuccessMessage('Google Ads connected. Conversion actions are being created in the background.');
    } catch (err) {
      if (err instanceof ValidationError) {
        Object.entries(err.fieldErrors).forEach(([field, messages]) => {
          form.setError(field as keyof GoogleAdsFormData, {
            message: messages[0],
          });
        });
      }
    }
  }

  const apiError = saveMutation.error instanceof Error ? saveMutation.error.message : null;
  const mappings = query.data?.mappings ?? [];

  return (
    <>
      {successMessage && (
        <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccessMessage(null)}>
          {successMessage}
        </Alert>
      )}

      {apiError && !(apiError === 'Validation failed') && (
        <Box sx={{ mb: 3 }}>
          <Alert severity="error" onClose={() => saveMutation.reset()}>
            {apiError}
          </Alert>
          {isPermissionError(apiError) && <PermissionTroubleshootingPanel />}
        </Box>
      )}

      {form.formState.errors.root && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {form.formState.errors.root.message}
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
              {...form.register('customer_id')}
              label="Customer ID"
              placeholder="123-456-7890"
              helperText={
                form.formState.errors.customer_id?.message ??
                'Your Google Ads account ID (not MCC) — shown top-right in Google Ads, format 123-456-7890'
              }
              error={!!form.formState.errors.customer_id}
              fullWidth
              required
            />
            <TextField
              {...form.register('mcc_id')}
              label="MCC Customer ID (optional)"
              placeholder="123-456-7890"
              helperText={
                form.formState.errors.mcc_id?.message ??
                "Leave blank if you don't use a manager account. If you do, this is your MCC's account ID, found the same way as Customer ID but for the manager account"
              }
              error={!!form.formState.errors.mcc_id}
              fullWidth
            />
            <TextField
              {...form.register('developer_token')}
              type="password"
              label={
                <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.75 }}>
                  Developer Token
                  {hasDeveloperToken && <Chip label="Set" color="success" size="small" sx={{ height: 18 }} />}
                </Box>
              }
              helperText={
                form.formState.errors.developer_token?.message ??
                (hasDeveloperToken
                  ? 'Already set — leave blank to keep the current value, or enter a new one to replace it.'
                  : 'From Google Ads → Tools & Settings → Setup → API Center')
              }
              error={!!form.formState.errors.developer_token}
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
                  : 'From Google OAuth Playground: use your own credentials above, authorize with the https://www.googleapis.com/auth/adwords scope, then exchange for tokens')
              }
              error={!!form.formState.errors.oauth_refresh_token}
              fullWidth
              multiline
              rows={3}
              slotProps={{ htmlInput: { style: { fontFamily: 'monospace' } } }}
            />
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
              <Button type="submit" variant="contained" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save & Connect'}
              </Button>
              {query.data?.integration?.active && (
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

      {mappings.length > 0 && (
        <Box sx={{ mt: 4 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>
            Conversion Actions
          </Typography>
          <Card variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Event</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Google Ads Action ID</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {mappings.map((mapping) => (
                  <TableRow key={mapping.id}>
                    <TableCell sx={{ fontFamily: 'monospace' }}>{mapping.event}</TableCell>
                    <TableCell>
                      <Chip
                        label={mapping.active ? 'Active' : 'Inactive'}
                        color={mapping.active ? 'success' : 'default'}
                        size="small"
                      />
                    </TableCell>
                    <TableCell sx={{ fontFamily: 'monospace', fontSize: 12 }}>
                      {mapping.external_action_id ?? '—'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Card>
        </Box>
      )}
    </>
  );
}
