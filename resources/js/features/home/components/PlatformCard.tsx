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
    <Card variant="outlined" sx={{ position: 'relative', overflow: 'hidden', transition: 'transform .18s ease, box-shadow .18s ease', '&:hover': { transform: 'translateY(-2px)', boxShadow: '0 8px 22px rgba(25,32,56,.08)' } }}>
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
            width: 42, height: 42, borderRadius: 2.5,
              bgcolor: bg,
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              fontWeight: 700, fontSize: 14, color,
            }}
          >
            {initial}
          </Box>
          <Box>
            <Typography variant="body2" sx={{ fontWeight: 700, color: 'text.primary' }}>{label}</Typography>
            <Chip
              label={connected ? 'Connected' : 'Not Connected'}
              color={connected ? 'success' : 'default'}
              size="small"
              sx={{ mt: 0.75, height: 23, fontSize: 11, fontWeight: 650 }}
            />
          </Box>
        </Box>
        <Box sx={{ display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, gap: 1, alignItems: 'center' }}>
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
