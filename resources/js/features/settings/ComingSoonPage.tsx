// resources/js/features/settings/ComingSoonPage.tsx
import { Box, Button, Typography } from '@mui/material';
import { useLocation, useNavigate } from 'react-router-dom';

export function ComingSoonPage() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const name = pathname
    .split('/')
    .pop()
    ?.replace('-', ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase()) ?? '';

  return (
    <Box sx={{ maxWidth: 600, mx: 'auto', p: 3, textAlign: 'center', mt: 8 }}>
      <Typography variant="h5" sx={{ fontWeight: 700 }} gutterBottom>
        {name} Integration
      </Typography>
      <Typography variant="body1" color="text.secondary" sx={{ mb: 3 }}>
        This integration is coming soon.
      </Typography>
      <Button variant="outlined" onClick={() => navigate('/')}>
        Back to Dashboard
      </Button>
    </Box>
  );
}
