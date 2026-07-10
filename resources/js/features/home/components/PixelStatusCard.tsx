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
    <Card variant="outlined" sx={{ mb: 4 }}>
      <CardContent sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
          <Switch
            checked={pixelEnabled}
            disabled={toggleMutation.isPending}
            onChange={handleChange}
            inputProps={{ 'aria-label': 'Toggle tracking pixel' }}
          />
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {pixelEnabled ? 'Tracking pixel is active' : 'Tracking pixel is inactive'}
          </Typography>
          {toggleMutation.isPending && <CircularProgress size={16} />}
        </Box>

        {pixelEnabled && pixelId && (
          <Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
            {pixelId}
          </Typography>
        )}

        {!pixelEnabled && !toggleMutation.isPending && (
          <Typography variant="caption" color="text.secondary">
            Turn this on to start sending conversion events to Shopify's Web Pixel.
          </Typography>
        )}

        {errorMessage && <Alert severity="error">{errorMessage}</Alert>}
      </CardContent>
    </Card>
  );
}
