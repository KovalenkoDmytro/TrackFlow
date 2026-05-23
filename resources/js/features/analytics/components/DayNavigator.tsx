// resources/js/features/analytics/components/DayNavigator.tsx
import { Box, Button, Typography } from '@mui/material';

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

function formatDateLabel(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);
  const d = new Date(year, month - 1, day);
  return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function shiftDate(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const d = new Date(year, month - 1, day + days);
  return d.toISOString().slice(0, 10);
}

interface DayNavigatorProps {
  date: string;
  onChange: (date: string) => void;
}

export function DayNavigator({ date, onChange }: DayNavigatorProps) {
  const isToday = date === todayString();

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
      <Button size="small" variant="outlined" onClick={() => onChange(shiftDate(date, -1))}>
        ← Prev
      </Button>
      <Typography variant="body2" sx={{ minWidth: 160, textAlign: 'center' }}>
        {formatDateLabel(date)}
      </Typography>
      <Button
        size="small"
        variant="outlined"
        onClick={() => onChange(shiftDate(date, 1))}
        disabled={date >= todayString()}
      >
        Next →
      </Button>
      <Button
        size="small"
        variant={isToday ? 'contained' : 'outlined'}
        onClick={() => onChange(todayString())}
        disabled={isToday}
      >
        Today
      </Button>
    </Box>
  );
}
