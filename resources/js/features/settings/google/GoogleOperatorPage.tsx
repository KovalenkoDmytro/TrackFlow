// resources/js/features/settings/google/GoogleOperatorPage.tsx
import { Alert, Box, Button, Card, CardContent, Typography } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { useGoogleOperatorStatus } from './hooks/useGoogleOperator';

/**
 * Operator-only panel for connecting/disconnecting the single shared Google
 * account used by GA4 Data API reporting (see App\Actions\Google). Only
 * meaningful for the shop(s) listed in APP_OPERATOR_EMAILS — real
 * enforcement happens server-side via the "connect-google" Gate; this page
 * just avoids showing the button to shops that would get a 403 anyway.
 */
export function GoogleOperatorPage() {
  const { data, isLoading, error } = useGoogleOperatorStatus();

  if (isLoading) return <LoadingState />;

  return (
    <PageLayout title="Google Account Connection" backTo="/">
      {error && <Alert severity="error" sx={{ mb: 3 }}>{error.message}</Alert>}

      {data && !data.is_operator && (
        <Alert severity="info">
          This shop is not authorized to manage the shared Google connection.
        </Alert>
      )}

      {data?.is_operator && (
        <Card variant="outlined">
          <CardContent sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <Typography variant="body1">
              Status: {data.connected ? 'Connected' : 'Not connected'}
              {data.connected_at ? ` (since ${new Date(data.connected_at).toLocaleDateString()})` : ''}
            </Typography>

            <Box sx={{ display: 'flex', gap: 1 }}>
              {!data.connected ? (
                <Button
                  variant="contained"
                  onClick={() => {
                    window.top!.location.href = '/operator/google/start';
                  }}
                >
                  Connect Google Account
                </Button>
              ) : (
                <form method="POST" action="/operator/google/disconnect">
                  <input type="hidden" name="_token" value={document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''} />
                  <Button type="submit" variant="outlined" color="error">
                    Disconnect
                  </Button>
                </form>
              )}
            </Box>
          </CardContent>
        </Card>
      )}
    </PageLayout>
  );
}
