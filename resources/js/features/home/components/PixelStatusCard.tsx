import { useState } from 'react';
import { Alert, Box, Card, CardContent, CircularProgress, Switch, Typography } from '@mui/material';
import { usePixelToggle } from '../hooks/usePixelToggle';

interface PixelStatusCardProps {
  pixelEnabled: boolean;
  pixelId: string | null | undefined;
}

export function PixelStatusCard({ pixelEnabled, pixelId }: PixelStatusCardProps) {
  const toggleMutation = usePixelToggle();
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleChange = (_event: unknown, checked: boolean) => {
    setErrorMessage(null);
    toggleMutation.mutate(checked, {
      onError: (error) => setErrorMessage(error instanceof Error ? error.message : 'Failed to update pixel status.'),
    });
  };

  return (
    <Card variant="outlined" sx={{ mb: 3, overflow: 'hidden' }}>
      <CardContent sx={{ display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, alignItems: { xs: 'stretch', sm: 'center' }, justifyContent: 'space-between', gap: 2, p: '18px 22px !important' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
          <Box sx={{ width: 42, height: 42, borderRadius: 2.5, display: 'grid', placeItems: 'center', bgcolor: pixelEnabled ? '#e9f7f0' : '#f1f2f6', color: pixelEnabled ? 'success.main' : 'text.secondary', fontWeight: 800 }}>P</Box>
          <Box>
          <Switch
            checked={pixelEnabled}
            disabled={toggleMutation.isPending}
            onChange={handleChange}
            slotProps={{ input: { 'aria-label': 'Toggle tracking pixel' } }}
          />
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {pixelEnabled ? 'Tracking pixel is active' : 'Tracking pixel is inactive'}
          </Typography>
          {toggleMutation.isPending && <CircularProgress size={16} />}
          <Typography variant="caption" color="text.secondary" sx={{ mt: .25, display: 'block' }}>{pixelEnabled ? 'Events are being collected for your store.' : 'Turn on to start collecting conversion events.'}</Typography>
          </Box>
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap', pl: { xs: 7, sm: 0 } }}>
        {pixelEnabled && pixelId && (
          <Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
            {pixelId}
          </Typography>
        )}
        {errorMessage && <Alert severity="error">{errorMessage}</Alert>}
        </Box>
      </CardContent>
    </Card>
  );
}
