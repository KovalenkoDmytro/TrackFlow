// resources/js/features/analytics/components/DayNavigator.tsx
import { Box, Button } from '@mui/material';
import { CalendarDatePicker } from './CalendarDatePicker';

function localDateString(date = new Date()): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function dateDaysAgo(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return localDateString(date);
}

function shiftDate(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const d = new Date(year, month - 1, day + days);
  return localDateString(d);
}

interface DayNavigatorProps {
  date: string;
  onChange: (date: string) => void;
  retentionDays: number;
}

export function DayNavigator({ date, onChange, retentionDays }: DayNavigatorProps) {
  const today = localDateString();
  const earliestDate = dateDaysAgo(retentionDays);
  const isToday = date === today;

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
      <Button size="small" variant="outlined" onClick={() => onChange(shiftDate(date, -1))} disabled={date <= earliestDate}>
        ← Prev
      </Button>
      <CalendarDatePicker value={date} onChange={onChange} retentionDays={retentionDays} />
      <Button
        size="small"
        variant="outlined"
        onClick={() => onChange(shiftDate(date, 1))}
        disabled={date >= today}
      >
        Next →
      </Button>
      <Button
        size="small"
        variant={isToday ? 'contained' : 'outlined'}
        onClick={() => onChange(today)}
        disabled={isToday}
      >
        Today
      </Button>
    </Box>
  );
}
