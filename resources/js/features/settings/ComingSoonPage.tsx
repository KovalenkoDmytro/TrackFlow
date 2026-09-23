// resources/js/features/settings/ComingSoonPage.tsx
import { Box, Button, Card, Typography } from '@mui/material';
import { useLocation, useNavigate } from 'react-router-dom';
import { PageLayout } from '../../components/ui/PageLayout';

export function ComingSoonPage() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const name = pathname
    .split('/')
    .pop()
    ?.replace('-', ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase()) ?? '';

  return <PageLayout title={`${name} integration`} backTo="/">
    <Card variant="outlined" sx={{ maxWidth: 720, mx: 'auto', p: { xs: 3, sm: 5 }, textAlign: 'center' }}>
      <Box sx={{ width: 56, height: 56, mx: 'auto', mb: 2, borderRadius: 3, display: 'grid', placeItems: 'center', bgcolor: '#f0f0ff', color: 'primary.main', fontSize: 22, fontWeight: 800 }}>T</Box>
      <Typography variant="h5" gutterBottom>{name} tracking is on the way</Typography>
      <Typography color="text.secondary" sx={{ mb: 3 }}>This integration is being prepared. You can continue managing your connected platforms from the overview.</Typography>
      <Button variant="contained" onClick={() => navigate('/')}>Back to overview</Button>
    </Card>
  </PageLayout>;
}
