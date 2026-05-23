// resources/js/components/ui/LoadingState.tsx
import { Box, CircularProgress } from '@mui/material';

export function LoadingState() {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'center', mt: 8 }}>
      <CircularProgress />
    </Box>
  );
}
