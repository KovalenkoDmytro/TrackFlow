import { Box, Typography } from '@mui/material';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import AutoGraphRoundedIcon from '@mui/icons-material/AutoGraphRounded';

const links = [
  { to: '/', label: 'Overview' },
  { to: '/analytics', label: 'Analytics' },
];

export function AppShell() {
  const { pathname } = useLocation();
  return (
    <Box sx={{ minHeight: '100vh', bgcolor: '#f5f6f3' }}>
      <Box component="header" sx={{ bgcolor: '#fff', borderBottom: '1px solid #e8ece6', px: { xs: 2, sm: 4, lg: 6 } }}>
        <Box sx={{ maxWidth: 1400, mx: 'auto', minHeight: 68, display: 'flex', alignItems: 'center', gap: { xs: 3, sm: 6 } }}>
          <Box component={NavLink} to="/" sx={{ display: 'flex', alignItems: 'center', gap: 1.2, flexShrink: 0, color: '#20352b', textDecoration: 'none' }}>
            <Box sx={{ width: 36, height: 36, borderRadius: '11px', bgcolor: '#dceecf', color: '#315e46', display: 'grid', placeItems: 'center' }}><AutoGraphRoundedIcon fontSize="small" /></Box>
            <Typography sx={{ fontWeight: 800, fontSize: 19, letterSpacing: '-.05em' }}>trackflow<span style={{ color: '#6d9b6d' }}>.</span></Typography>
          </Box>
          <Box component="nav" aria-label="Main navigation" sx={{ display: 'flex', alignSelf: 'stretch', gap: { xs: 2, sm: 3 } }}>
            {links.map(({ to, label }) => {
              const active = to === '/' ? pathname === '/' || pathname.startsWith('/settings') : pathname.startsWith('/analytics');
              return <Box key={to} component={NavLink} to={to} sx={{ display: 'flex', alignItems: 'center', borderBottom: '2px solid', borderColor: active ? '#315e46' : 'transparent', color: active ? '#315e46' : '#87948b', textDecoration: 'none', fontSize: 13, fontWeight: active ? 750 : 600, '&:hover': { color: '#315e46' } }}>{label}</Box>;
            })}
          </Box>
        </Box>
      </Box>
      <Box component="main" sx={{ px: { xs: 2, sm: 4, lg: 6 }, py: { xs: 3, md: 5 } }}><Box sx={{ maxWidth: 1400, mx: 'auto' }}><Outlet /></Box></Box>
    </Box>
  );
}
