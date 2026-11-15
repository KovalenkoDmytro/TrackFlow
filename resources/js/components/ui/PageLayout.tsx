// resources/js/components/ui/PageLayout.tsx
import { Box, Button, Typography } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import type { ReactNode } from 'react';

interface PageLayoutProps {
  title: string;
  maxWidth?: number;
  backTo?: string;
  actions?: ReactNode;
  children: ReactNode;
}

export function PageLayout({ title, maxWidth = 1120, backTo, actions, children }: PageLayoutProps) {
  const navigate = useNavigate();

  return (
    <Box sx={{ width: '100%', maxWidth, mx: 'auto', px: 0, py: 0 }}>
      {backTo && (
        <Button variant="text" size="small" sx={{ mb: 1.5, ml: -1, color: 'text.secondary' }} onClick={() => navigate(backTo)}>
          ← Back to overview
        </Button>
      )}
      <Box sx={{ display: 'flex', alignItems: { xs: 'flex-start', sm: 'center' }, justifyContent: 'space-between', gap: 2, mb: 3 }}>
        <Typography variant="h5" sx={{ fontSize: { xs: 25, md: 30 } }}>
          {title}
        </Typography>
        {actions}
      </Box>
      {children}
    </Box>
  );
}
