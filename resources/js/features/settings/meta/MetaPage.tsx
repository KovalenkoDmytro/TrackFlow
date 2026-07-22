// resources/js/features/settings/meta/MetaPage.tsx
import { Alert } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { MetaForm } from './MetaForm';
import { useMetaSettings } from './hooks/useMetaSettings';

export function MetaPage() {
  const { query, disconnectMutation } = useMetaSettings();

  if (query.isLoading) return <LoadingState />;

  if (query.error) {
    return (
      <PageLayout title="Meta Conversions API Integration" backTo="/">
        <Alert severity="error">{query.error.message}</Alert>
      </PageLayout>
    );
  }

  async function handleDisconnect() {
    if (!window.confirm('Disconnect Meta? This will stop all conversion tracking.')) return;
    await disconnectMutation.mutateAsync();
  }

  return (
    <PageLayout title="Meta Conversions API Integration" backTo="/">
      <MetaForm
        onDisconnect={handleDisconnect}
        isDisconnecting={disconnectMutation.isPending}
      />
    </PageLayout>
  );
}
