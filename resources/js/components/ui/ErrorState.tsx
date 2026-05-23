// resources/js/components/ui/ErrorState.tsx
import { Alert, Box } from '@mui/material';

interface ErrorStateProps {
  message: string;
}

export function ErrorState({ message }: ErrorStateProps) {
  return (
    <Box sx={{ p: 3 }}>
      <Alert severity="error">{message}</Alert>
    </Box>
  );
}
