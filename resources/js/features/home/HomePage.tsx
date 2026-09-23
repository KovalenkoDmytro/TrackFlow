import { useNavigate } from 'react-router-dom';
import { Alert, Box, Button, Chip, Typography } from '@mui/material';
import { useShopStatus } from './hooks/useShopStatus';
import { LoadingState } from '../../components/ui/LoadingState';
import { PixelStatusCard } from './components/PixelStatusCard';
import { PlatformCard } from './components/PlatformCard';

const PLATFORMS = [
  { key: 'google_ads', label: 'Google Ads', initial: 'G', color: '#4285F4', bg: '#e8f0fe', route: '/settings/google-ads' },
  { key: 'meta',       label: 'Meta',        initial: 'M', color: '#1877F2', bg: '#e7f3ff', route: '/settings/meta' },
  { key: 'tiktok',     label: 'TikTok',      initial: 'T', color: '#ffffff', bg: '#010101', route: '/settings/tiktok', comingSoon: true },
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

  const connectedCount = PLATFORMS.filter((platform) => Boolean(integrations[platform.key])).length;
  return (
    <Box sx={{ width: '100%', px: { xs: 1.5, sm: 2.5 }, py: { xs: 2.5, sm: 3 } }}>
      <Box sx={{ display: 'flex', alignItems: { xs: 'flex-start', sm: 'center' }, justifyContent: 'space-between', gap: 2, mb: 3.5 }}>
        <Box><Typography variant="h4" sx={{ fontSize: { xs: 27, md: 32 } }}>Good to see you</Typography><Typography color="text.secondary" sx={{ mt: .75 }}>Manage your store’s tracking and integrations from one place.</Typography></Box>
        <Button variant="contained" onClick={() => navigate('/analytics')} sx={{ flexShrink: 0 }}>View analytics</Button>
      </Box>

      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, gap: 1.5, mb: 3 }}>
        <Box sx={{ bgcolor: '#fff', border: '1px solid', borderColor: 'divider', borderRadius: 1, p: 2.5, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}><Box><Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700, letterSpacing: '.04em' }}>TRACKING PIXEL</Typography><Typography variant="h5" sx={{ mt: .5, fontSize: 23 }}>{data?.shop?.pixel_enabled ? 'Active' : 'Inactive'}</Typography></Box><Chip size="small" color={data?.shop?.pixel_enabled ? 'success' : 'default'} label={data?.shop?.pixel_enabled ? 'Collecting events' : 'Action needed'} /></Box>
        <Box sx={{ bgcolor: '#fff', border: '1px solid', borderColor: 'divider', borderRadius: 1, p: 2.5, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}><Box><Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700, letterSpacing: '.04em' }}>CONNECTED PLATFORMS</Typography><Typography variant="h5" sx={{ mt: .5, fontSize: 23 }}>{connectedCount}<Typography component="span" color="text.secondary" sx={{ fontSize: 15, fontWeight: 500 }}> / {PLATFORMS.length} platforms</Typography></Typography></Box><Box sx={{ display: 'flex' }}>{PLATFORMS.slice(0, 4).map((p) => <Box key={p.key} sx={{ width: 30, height: 30, ml: -0.5, borderRadius: '50%', border: '2px solid white', bgcolor: p.bg, color: p.color, display: 'grid', placeItems: 'center', fontSize: 11, fontWeight: 800 }}>{p.initial}</Box>)}</Box></Box>
      </Box>

      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1.5 }}><Box><Typography variant="h6">Tracking pixel</Typography><Typography variant="body2" color="text.secondary">Control event collection for your Shopify store.</Typography></Box></Box>
      <PixelStatusCard
        pixelEnabled={Boolean(data?.shop?.pixel_enabled)}
        pixelId={data?.shop?.shopify_pixel_id}
      />

      <Box sx={{ display: 'flex', alignItems: 'end', justifyContent: 'space-between', mb: 1.5, mt: 3 }}><Box><Typography variant="h6">Your integrations</Typography><Typography variant="body2" color="text.secondary">Connect platforms to send conversion events.</Typography></Box><Typography variant="caption" color="text.secondary">{connectedCount} connected</Typography></Box>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, gap: 2 }}>
        {PLATFORMS.map((platform) => (
          <PlatformCard
            key={platform.key}
            label={platform.label}
            initial={platform.initial}
            color={platform.color}
            bg={platform.bg}
            connected={Boolean(integrations[platform.key])}
            comingSoon={'comingSoon' in platform && platform.comingSoon}
            onNavigate={() => navigate(platform.route)}
            onViewEvents={() => navigate(`/analytics/platform/${platform.key}`)}
          />
        ))}
      </Box>
    </Box>
  );
}
