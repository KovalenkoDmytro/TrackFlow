import { Box, Button, Card, CardContent, Chip, Typography } from '@mui/material';

interface PlatformCardProps {
  label: string;
  initial: string;
  color: string;
  bg: string;
  connected: boolean;
  onNavigate: () => void;
  onViewEvents: () => void;
}

export function PlatformCard({ label, initial, color, bg, connected, onNavigate, onViewEvents }: PlatformCardProps) {
  return (
    <Card variant="outlined">
      <CardContent sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
          <Box
            sx={{
              width: 36, height: 36, borderRadius: '50%',
              bgcolor: bg,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              fontWeight: 700, fontSize: 14, color,
            }}
          >
            {initial}
          </Box>
          <Box>
            <Typography variant="body2" sx={{ fontWeight: 500 }}>{label}</Typography>
            <Chip
              label={connected ? 'Connected' : 'Not Connected'}
              color={connected ? 'success' : 'default'}
              size="small"
              sx={{ mt: 0.5 }}
            />
          </Box>
        </Box>
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1, alignItems: 'flex-end' }}>
          <Button variant="outlined" size="small" onClick={onNavigate}>
            {connected ? 'Manage' : 'Connect'}
          </Button>
          <Button variant="outlined" size="small" onClick={onViewEvents}>
            Events
          </Button>
        </Box>
      </CardContent>
    </Card>
  );
}
