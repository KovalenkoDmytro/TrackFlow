import { useNavigate } from 'react-router-dom';
import { Alert, Box, Button, Typography } from '@mui/material';
import ArrowForwardRoundedIcon from '@mui/icons-material/ArrowForwardRounded';
import HubOutlinedIcon from '@mui/icons-material/HubOutlined';
import SensorsRoundedIcon from '@mui/icons-material/SensorsRounded';
import { useShopStatus } from './hooks/useShopStatus';
import { LoadingState } from '../../components/ui/LoadingState';
import { PixelStatusCard } from './components/PixelStatusCard';
import { PlatformCard } from './components/PlatformCard';

const PLATFORMS = [
  { key: 'google_ads', label: 'Google Ads', initial: 'G', color: '#4285F4', bg: '#edf3ff', route: '/settings/google-ads' },
  { key: 'meta', label: 'Meta', initial: 'M', color: '#1877F2', bg: '#eaf3ff', route: '/settings/meta' },
  { key: 'ga4', label: 'Google Analytics 4', initial: 'A', color: '#d77900', bg: '#fff3dc', route: '/settings/ga4' },
  { key: 'tiktok', label: 'TikTok', initial: 'T', color: '#ffffff', bg: '#202624', route: '/settings/tiktok', comingSoon: true },
] as const;

export function HomePage() {
  const navigate = useNavigate();
  const { data, isLoading, error } = useShopStatus();
  if (isLoading) return <LoadingState />;
  if (error) return <Alert severity="error">{error.message}</Alert>;
  const integrations = data?.integrations ?? {};
  const connectedCount = PLATFORMS.filter((platform) => Boolean(integrations[platform.key])).length;
  const pixelEnabled = Boolean(data?.shop?.pixel_enabled);
  return (
    <Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: { xs: 'flex-start', sm: 'center' }, gap: 2, flexWrap: 'wrap', mb: 4 }}>
        <Box><Typography sx={{ color: '#71837a', fontSize: 11, fontWeight: 800, letterSpacing: '.14em', mb: 1 }}>YOUR WORKSPACE / OVERVIEW</Typography><Typography variant="h4" sx={{ fontSize: { xs: 30, md: 38 } }}>Tracking overview</Typography><Typography color="text.secondary" sx={{ mt: 1 }}>Everything you need to keep your conversion data flowing.</Typography></Box>
        <Button variant="outlined" endIcon={<ArrowForwardRoundedIcon />} onClick={() => navigate('/analytics')}>View analytics</Button>
      </Box>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', lg: '1.45fr 1fr' }, gap: 2, mb: 4 }}>
        <Box sx={{ position: 'relative', overflow: 'hidden', minHeight: 220, p: { xs: 3, md: 4 }, borderRadius: '20px', bgcolor: '#dceecf', display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
          <Box sx={{ position: 'absolute', width: 240, height: 240, border: '1px solid rgba(49,96,59,.15)', borderRadius: '50%', right: -35, top: -75, boxShadow: '0 0 0 35px rgba(255,255,255,.12), 0 0 0 70px rgba(255,255,255,.1)' }} />
          <Box sx={{ position: 'relative' }}><Typography sx={{ color: '#56735a', fontSize: 11, letterSpacing: '.13em', fontWeight: 800 }}>YOUR STORE, CONNECTED</Typography><Typography variant="h5" sx={{ mt: 1.5, maxWidth: 430, fontSize: { xs: 25, md: 29 }, lineHeight: 1.2 }}>Make every event count.</Typography><Typography sx={{ color: '#526b55', maxWidth: 390, mt: 1, fontSize: 14 }}>A simple home for your pixel and the platforms that power your growth.</Typography></Box>
          <Box sx={{ position: 'relative', mt: 3, display: 'flex', alignItems: 'center', gap: 1 }}><Box sx={{ width: 7, height: 7, borderRadius: '50%', bgcolor: '#4f8a57' }} /><Typography sx={{ fontSize: 12, color: '#46644a', fontWeight: 700, overflowWrap: 'anywhere' }}>{data?.shop?.name || 'Your Shopify store'}</Typography></Box>
        </Box>
        <Box sx={{ display: 'grid', gridTemplateRows: '1fr 1fr', gap: 2 }}>
          {[{ label: 'TRACKING PIXEL', value: pixelEnabled ? 'Active' : 'Inactive', detail: pixelEnabled ? 'Collecting events' : 'Ready to set up', icon: SensorsRoundedIcon }, { label: 'CONNECTED PLATFORMS', value: `${connectedCount} connected`, detail: 'Across your integrations', icon: HubOutlinedIcon }].map(({ label, value, detail, icon: Icon }) => <Box key={label} sx={{ bgcolor: '#fff', border: '1px solid #e8ece6', borderRadius: '18px', p: 2.5, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}><Box><Typography sx={{ fontSize: 10, letterSpacing: '.12em', fontWeight: 800, color: '#87948b' }}>{label}</Typography><Typography sx={{ mt: .7, fontSize: 22, fontWeight: 750, letterSpacing: '-.04em' }}>{value}</Typography><Typography sx={{ color: '#829087', fontSize: 12, mt: .25 }}>{detail}</Typography></Box><Box sx={{ width: 44, height: 44, borderRadius: '14px', bgcolor: '#f0f5ee', color: '#577b5c', display: 'grid', placeItems: 'center' }}><Icon /></Box></Box>)}
        </Box>
      </Box>
      <Box sx={{ mb: 1.8 }}><Typography variant="h6">Your tracking pixel</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: .35 }}>Manage event collection for your Shopify store.</Typography></Box>
      <PixelStatusCard pixelEnabled={pixelEnabled} pixelId={data?.shop?.shopify_pixel_id} />
      <Box sx={{ display: 'flex', alignItems: 'end', justifyContent: 'space-between', gap: 2, mb: 1.8, mt: 4 }}><Box><Typography variant="h6">Your integrations</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: .35 }}>Connect your platforms and put your data to work.</Typography></Box><Typography sx={{ fontSize: 12, color: '#829087', whiteSpace: 'nowrap' }}>{connectedCount} connected</Typography></Box>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', xl: '1fr 1fr' }, gap: 2 }}>{PLATFORMS.map((platform) => <PlatformCard key={platform.key} label={platform.label} initial={platform.initial} color={platform.color} bg={platform.bg} connected={Boolean(integrations[platform.key])} comingSoon={'comingSoon' in platform && platform.comingSoon} onNavigate={() => navigate(platform.route)} onViewEvents={() => navigate(`/analytics/platform/${platform.key}`)} />)}</Box>
    </Box>
  );
}
