// resources/js/features/settings/google-ads/GoogleAdsPage.tsx
import { Alert } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { GoogleAdsForm } from './GoogleAdsForm';
import { useGoogleAdsSettings } from './hooks/useGoogleAdsSettings';

export function GoogleAdsPage() {
  const { query, disconnectMutation } = useGoogleAdsSettings();

  if (query.isLoading) return <LoadingState />;

  if (query.error) {
    return (
      <PageLayout title="Google Ads Integration" backTo="/">
        <Alert severity="error">{query.error.message}</Alert>
      </PageLayout>
    );
  }

  async function handleDisconnect() {
    if (!window.confirm('Disconnect Google Ads? This will stop all conversion tracking.')) return;
    await disconnectMutation.mutateAsync();
  }

  return (
    <PageLayout title="Google Ads Integration" backTo="/">
      <GoogleAdsForm
        onDisconnect={handleDisconnect}
        isDisconnecting={disconnectMutation.isPending}
      />
    </PageLayout>
  );
}
