import { Box, Button, Card, CardContent, Chip, Typography } from '@mui/material';

interface PlatformCardProps {
  label: string;
  initial: string;
  color: string;
  bg: string;
  connected: boolean;
  comingSoon?: boolean;
  onNavigate: () => void;
  onViewEvents: () => void;
}

export function PlatformCard({ label, initial, color, bg, connected, comingSoon = false, onNavigate, onViewEvents }: PlatformCardProps) {
  return (
    <Card variant="outlined" sx={{ position: 'relative', overflow: 'hidden' }}>
      {comingSoon && (
        <Chip
          label="Coming soon"
          color="primary"
          size="small"
          sx={{
            position: 'absolute',
            top: 8,
            right: 8,
            zIndex: 1,
            fontWeight: 600,
          }}
        />
      )}
      <CardContent
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          ...(comingSoon && {
            filter: 'blur(2px)',
            pointerEvents: 'none',
            userSelect: 'none',
          }),
        }}
      >
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
        <Box sx={{ display: 'flex', flexDirection: 'raw', gap: 1, alignItems: 'flex-end' }}>
            {
                connected && <Button variant="contained" size="small" onClick={onViewEvents}>Events</Button>
            }
          <Button variant="outlined" size="small" onClick={onNavigate}>
            {connected ? 'Manage' : 'Connect'}
          </Button>
        </Box>
      </CardContent>
    </Card>
  );
}
