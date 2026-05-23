// resources/js/features/home/HomePage.tsx
import { useNavigate } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Typography,
} from '@mui/material';
import { useShopStatus } from './hooks/useShopStatus';
import { LoadingState } from '../../components/ui/LoadingState';

const PLATFORMS = [
  { key: 'google_ads', label: 'Google Ads',  initial: 'G', color: '#4285F4', bg: '#e8f0fe', route: '/settings/google-ads' },
  { key: 'meta',       label: 'Meta',         initial: 'M', color: '#1877F2', bg: '#e7f3ff', route: '/settings/meta' },
  { key: 'tiktok',     label: 'TikTok',       initial: 'T', color: '#ffffff', bg: '#010101', route: '/settings/tiktok' },
  { key: 'ga4',        label: 'GA4',           initial: 'A', color: '#E37400', bg: '#fff3e0', route: '/settings/ga4' },
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

  const shop = data?.shop;
  const integrations = data?.integrations ?? {};

  return (
    <Box sx={{ maxWidth: 900, mx: 'auto', p: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
        <Typography variant="h5" sx={{ fontWeight: 700 }}>
          TrackFlow
        </Typography>
        <Button variant="outlined" size="small" onClick={() => navigate('/analytics')}>
          Analytics
        </Button>
      </Box>

      <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>
        Pixel Status
      </Typography>
      <Card variant="outlined" sx={{ mb: 4 }}>
        <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
          {shop?.shopify_pixel_id ? (
            <>
              <Chip label="Active" color="success" size="small" />
              <Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
                {shop.shopify_pixel_id}
              </Typography>
            </>
          ) : (
            <Chip label="Inactive — pixel will be created on next authentication" size="small" />
          )}
        </CardContent>
      </Card>

      <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>
        Platform Integrations
      </Typography>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 2 }}>
        {PLATFORMS.map((platform) => {
          const connected = Boolean(integrations[platform.key]);
          return (
            <Box key={platform.key}>
              <Card variant="outlined">
                <CardContent sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                    <Box
                      sx={{
                        width: 36, height: 36, borderRadius: '50%',
                        bgcolor: platform.bg,
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontWeight: 700, fontSize: 14, color: platform.color,
                      }}
                    >
                      {platform.initial}
                    </Box>
                    <Box>
                      <Typography variant="body2" sx={{ fontWeight: 500 }}>{platform.label}</Typography>
                      <Chip
                        label={connected ? 'Connected' : 'Not Connected'}
                        color={connected ? 'success' : 'default'}
                        size="small"
                        sx={{ mt: 0.5 }}
                      />
                    </Box>
                  </Box>
                  <Button
                    variant="outlined"
                    size="small"
                    onClick={() => navigate(platform.route)}
                  >
                    {connected ? 'Manage' : 'Connect'}
                  </Button>
                </CardContent>
              </Card>
            </Box>
          );
        })}
      </Box>
    </Box>
  );
}
