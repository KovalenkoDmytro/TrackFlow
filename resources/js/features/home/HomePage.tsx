import { useNavigate } from 'react-router-dom';
import { Alert, Box, Button, Typography } from '@mui/material';
import { useShopStatus } from './hooks/useShopStatus';
import { LoadingState } from '../../components/ui/LoadingState';
import { PixelStatusCard } from './components/PixelStatusCard';
import { PlatformCard } from './components/PlatformCard';

const PLATFORMS = [
  { key: 'google_ads', label: 'Google Ads', initial: 'G', color: '#4285F4', bg: '#e8f0fe', route: '/settings/google-ads' },
  { key: 'meta',       label: 'Meta',        initial: 'M', color: '#1877F2', bg: '#e7f3ff', route: '/settings/meta' },
  { key: 'tiktok',     label: 'TikTok',      initial: 'T', color: '#ffffff', bg: '#010101', route: '/settings/tiktok' },
  { key: 'ga4',        label: 'GA4',          initial: 'A', color: '#E37400', bg: '#fff3e0', route: '/settings/ga4' },
] as const;

export function HomePage() {
  const navigate = useNavigate();
  const { data, isLoading, error } = useShopStatus();

  if (isLoading) return <LoadingState />;

  if (error) {
    return (
      <Box sx={{ p: 3 }}>
        <Alert severity="error">{error.message}</Alert>
      </Box>
    );
  }

  const integrations = data?.integrations ?? {};

  return (
    <Box sx={{ maxWidth: 900, mx: 'auto', p: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
        <Typography variant="h5" sx={{ fontWeight: 700 }}>
          TrackFlow
        </Typography>
        <Button variant="contained" size="small" onClick={() => navigate('/analytics')}>
          Analytics
        </Button>
      </Box>

      <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>
        Pixel Status
      </Typography>
      <PixelStatusCard
        pixelEnabled={Boolean(data?.shop?.pixel_enabled)}
        pixelId={data?.shop?.shopify_pixel_id}
      />

      <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>
        Platform Integrations
      </Typography>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2 }}>
        {PLATFORMS.map((platform) => (
          <PlatformCard
            key={platform.key}
            label={platform.label}
            initial={platform.initial}
            color={platform.color}
            bg={platform.bg}
            connected={Boolean(integrations[platform.key])}
            onNavigate={() => navigate(platform.route)}
            onViewEvents={() => navigate(`/analytics/platform/${platform.key}`)}
          />
        ))}
      </Box>
    </Box>
  );
}
