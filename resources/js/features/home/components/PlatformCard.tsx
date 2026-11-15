import { Box, Button, Card, Chip, Typography } from '@mui/material';
import ArrowForwardRoundedIcon from '@mui/icons-material/ArrowForwardRounded';

interface PlatformCardProps {
  label: string; initial: string; color: string; bg: string; connected: boolean; comingSoon?: boolean; onNavigate: () => void; onViewEvents: () => void;
}

export function PlatformCard({ label, initial, color, bg, connected, comingSoon = false, onNavigate, onViewEvents }: PlatformCardProps) {
  return (
    <Card variant="outlined" sx={{ p: 2.5, display: 'flex', flexDirection: { xs: 'column', sm: 'row' }, alignItems: { xs: 'stretch', sm: 'center' }, justifyContent: 'space-between', gap: 2, transition: 'border-color .2s, box-shadow .2s', '&:hover': { borderColor: '#cbdccc', boxShadow: '0 10px 30px rgba(29,61,42,.05)' } }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.75, minWidth: 0 }}>
        <Box sx={{ width: 48, height: 48, flexShrink: 0, borderRadius: '14px', bgcolor: bg, color, display: 'grid', placeItems: 'center', fontSize: 19, fontWeight: 800 }}>{initial}</Box>
        <Box sx={{ minWidth: 0 }}><Typography sx={{ fontSize: 14, fontWeight: 750 }}>{label}</Typography><Box sx={{ display: 'flex', alignItems: 'center', gap: .7, mt: .5 }}><Box sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: comingSoon ? '#aeb8b0' : connected ? '#61a775' : '#c2cbc3' }} /><Typography sx={{ fontSize: 12, color: '#829087' }}>{comingSoon ? 'Coming soon' : connected ? 'Connected' : 'Not connected'}</Typography></Box></Box>
      </Box>
      {comingSoon ? <Chip label="Coming soon" size="small" sx={{ alignSelf: { xs: 'flex-start', sm: 'center' }, bgcolor: '#f1f4ef', color: '#748478', fontWeight: 700 }} /> : <Box sx={{ display: 'flex', gap: .75, flexShrink: 0 }}>{connected && <Button size="small" onClick={onViewEvents}>Events</Button>}<Button variant="outlined" size="small" endIcon={<ArrowForwardRoundedIcon />} onClick={onNavigate}>{connected ? 'Manage' : 'Connect'}</Button></Box>}
    </Card>
  );
}
