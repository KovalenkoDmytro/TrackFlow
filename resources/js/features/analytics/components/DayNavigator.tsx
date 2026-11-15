// resources/js/features/analytics/components/DayNavigator.tsx
import { Box, Button, IconButton } from '@mui/material';
import ChevronLeftRoundedIcon from '@mui/icons-material/ChevronLeftRounded';
import ChevronRightRoundedIcon from '@mui/icons-material/ChevronRightRounded';
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
  const yesterday = dateDaysAgo(1);
  const earliestDate = dateDaysAgo(retentionDays);
  const isToday = date === today;
  const isYesterday = date === yesterday;

  return (
    <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 1 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: .5, p: .5, border: '1px solid #e5ebe4', borderRadius: '12px', bgcolor: '#fff' }}>
        <IconButton size="small" aria-label="Previous day" onClick={() => onChange(shiftDate(date, -1))} disabled={date <= earliestDate} sx={{ color: '#64806a' }}><ChevronLeftRoundedIcon fontSize="small" /></IconButton>
        <CalendarDatePicker value={date} onChange={onChange} retentionDays={retentionDays} />
        <IconButton size="small" aria-label="Next day" onClick={() => onChange(shiftDate(date, 1))} disabled={date >= today} sx={{ color: '#64806a' }}><ChevronRightRoundedIcon fontSize="small" /></IconButton>
      </Box>
      <Button size="small" variant="text" aria-pressed={isToday} onClick={() => onChange(today)} disabled={isToday} sx={{ borderRadius: '10px', px: 1.5, bgcolor: isToday ? '#eaf3e7' : 'transparent', '&.Mui-disabled': { color: '#487554' } }}>Today</Button>
      <Button size="small" variant="text" aria-pressed={isYesterday} onClick={() => onChange(yesterday)} disabled={isYesterday || yesterday < earliestDate} sx={{ borderRadius: '10px', px: 1.5, bgcolor: isYesterday ? '#eaf3e7' : 'transparent', '&.Mui-disabled': { color: isYesterday ? '#487554' : undefined } }}>Yesterday</Button>
    </Box>
  );
}
