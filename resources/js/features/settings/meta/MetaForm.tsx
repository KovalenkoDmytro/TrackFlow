// resources/js/features/settings/meta/MetaForm.tsx
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
import { useMetaSettings } from './hooks/useMetaSettings';
import { ValidationError } from '../../../types/api';
import type { MetaFormData } from '../../../types/api';

const schema = z.object({
  pixel_id: z.string().min(1, 'Required'),
  access_token: z.string(),
  test_event_code: z.string(),
});

const EMPTY_FORM: MetaFormData = {
  pixel_id: '',
  access_token: '',
  test_event_code: '',
};

interface MetaFormProps {
  onDisconnect: () => void;
  isDisconnecting: boolean;
}

export function MetaForm({ onDisconnect, isDisconnecting }: MetaFormProps) {
  const { query, saveMutation } = useMetaSettings();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const form = useForm<MetaFormData>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_FORM,
  });

  useEffect(() => {
    const creds = query.data?.credentials;
    if (creds) {
      form.reset({
        pixel_id: creds.pixel_id ?? '',
        // Access token is write-only — the API never returns the stored value,
        // so this always starts empty.
        access_token: '',
        test_event_code: creds.test_event_code ?? '',
      });
    }
  }, [query.data, form]);

  const credentials = query.data?.credentials;
  const hasAccessToken = credentials?.has_access_token ?? false;

  async function onSubmit(data: MetaFormData) {
    setSuccessMessage(null);
    try {
      await saveMutation.mutateAsync(data);
      setSuccessMessage('Meta connected. Conversion events are being configured in the background.');
    } catch (err) {
      if (err instanceof ValidationError) {
        Object.entries(err.fieldErrors).forEach(([field, messages]) => {
          form.setError(field as keyof MetaFormData, {
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
        </Box>
      )}

      {form.formState.errors.root && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {form.formState.errors.root.message}
        </Alert>
      )}

      <Alert severity="warning" sx={{ mb: 3 }}>
        If you already have a Meta Pixel installed elsewhere for this store (for example, via
        Shopify&apos;s Facebook &amp; Instagram sales channel), connecting this integration may
        cause the same conversion to be counted twice in Meta Events Manager. If you see duplicate
        conversions after connecting, consider disabling the other Meta Pixel integration for this
        store.
      </Alert>

      <Card variant="outlined">
        <CardContent>
          <Box
            component="form"
            onSubmit={form.handleSubmit(onSubmit)}
            sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}
          >
            <TextField
              {...form.register('pixel_id')}
              label="Pixel ID"
              helperText={
                form.formState.errors.pixel_id?.message ??
                'Find this in Meta Events Manager → Data Sources → your pixel'
              }
              error={!!form.formState.errors.pixel_id}
              fullWidth
              required
            />
            <TextField
              {...form.register('access_token')}
              type="password"
              label={
                <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.75 }}>
                  Access Token
                  {hasAccessToken && <Chip label="Set" color="success" size="small" sx={{ height: 18 }} />}
                </Box>
              }
              helperText={
                form.formState.errors.access_token?.message ??
                (hasAccessToken
                  ? 'Already set — leave blank to keep the current value, or enter a new one to replace it.'
                  : 'Events Manager → Datasets → Conversions API → Set up direct integration → Generate access token')
              }
              error={!!form.formState.errors.access_token}
              fullWidth
            />
            <TextField
              {...form.register('test_event_code')}
              label="Test Event Code (optional)"
              helperText={
                form.formState.errors.test_event_code?.message ??
                'Optional — from Meta Events Manager → Test Events tab, lets you verify events arrive before going live'
              }
              error={!!form.formState.errors.test_event_code}
              fullWidth
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
            Conversion Events
          </Typography>
          <Card variant="outlined">
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Event</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Meta Event Name</TableCell>
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
