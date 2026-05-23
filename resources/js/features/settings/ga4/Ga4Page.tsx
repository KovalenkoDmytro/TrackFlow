// resources/js/features/settings/ga4/Ga4Page.tsx
import { Alert } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { Ga4Form } from './Ga4Form';
import { useGa4Settings } from './hooks/useGa4Settings';

export function Ga4Page() {
  const { query, disconnectMutation } = useGa4Settings();

  if (query.isLoading) return <LoadingState />;

  if (query.error) {
    return (
      <PageLayout title="Google Analytics 4 Integration" backTo="/">
        <Alert severity="error">{query.error.message}</Alert>
      </PageLayout>
    );
  }

  async function handleDisconnect() {
    if (!window.confirm('Disconnect GA4? This will stop all GA4 event tracking.')) return;
    await disconnectMutation.mutateAsync();
  }

  return (
    <PageLayout title="Google Analytics 4 Integration" backTo="/">
      <Ga4Form
        onDisconnect={handleDisconnect}
        isDisconnecting={disconnectMutation.isPending}
      />
    </PageLayout>
  );
}
