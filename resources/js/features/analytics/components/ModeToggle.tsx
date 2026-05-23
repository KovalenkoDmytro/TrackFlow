// resources/js/features/analytics/components/ModeToggle.tsx
import { Button, ButtonGroup } from '@mui/material';
import type { AnalyticsMode } from '../../../types/api';

interface ModeToggleProps {
  mode: AnalyticsMode;
  onChange: (mode: AnalyticsMode) => void;
}

export function ModeToggle({ mode, onChange }: ModeToggleProps) {
  return (
    <ButtonGroup size="small" variant="outlined">
      <Button
        variant={mode === 'single_day' ? 'contained' : 'outlined'}
        onClick={() => onChange('single_day')}
      >
        Single Day
      </Button>
      <Button
        variant={mode === 'range' ? 'contained' : 'outlined'}
        onClick={() => onChange('range')}
      >
        Date Range
      </Button>
    </ButtonGroup>
  );
}
