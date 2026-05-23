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

export function PageLayout({ title, maxWidth = 720, backTo, actions, children }: PageLayoutProps) {
  const navigate = useNavigate();

  return (
    <Box sx={{ maxWidth, mx: 'auto', p: 3 }}>
      {backTo && (
        <Button variant="text" size="small" sx={{ mb: 2 }} onClick={() => navigate(backTo)}>
          ← Back
        </Button>
      )}
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 3 }}>
        <Typography variant="h6" sx={{ fontWeight: 700 }}>
          {title}
        </Typography>
        {actions}
      </Box>
      {children}
    </Box>
  );
}
