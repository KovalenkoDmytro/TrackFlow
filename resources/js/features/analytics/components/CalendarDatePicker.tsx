import { useState } from 'react';
import { Box, Button, Popover, Typography } from '@mui/material';
import CalendarMonthRoundedIcon from '@mui/icons-material/CalendarMonthRounded';
import { DayPicker } from 'react-day-picker';

function toDate(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

function toIso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function dateDaysAgo(days: number): Date {
  const date = new Date();
  date.setHours(0, 0, 0, 0);
  date.setDate(date.getDate() - days);
  return date;
}

interface CalendarDatePickerProps {
  value: string;
  onChange: (date: string) => void;
  retentionDays: number;
}

export function CalendarDatePicker({ value, onChange, retentionDays }: CalendarDatePickerProps) {
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const selected = toDate(value);
  const label = selected.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

  return <>
    <Button variant="outlined" size="small" startIcon={<CalendarMonthRoundedIcon />} onClick={(event) => setAnchor(event.currentTarget)} aria-label={`Choose date, currently ${label}`}>
      {label}
    </Button>
    <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }} transformOrigin={{ vertical: 'top', horizontal: 'left' }}>
      <Box sx={{ p: 1.5 }}>
        <Typography variant="subtitle2" sx={{ px: 1, pt: .5, fontWeight: 700 }}>Choose a day</Typography>
        <DayPicker className="trackflow-calendar" mode="single" selected={selected} onSelect={(date) => { if (date) { onChange(toIso(date)); setAnchor(null); } }} disabled={{ before: dateDaysAgo(retentionDays), after: new Date() }} />
      </Box>
    </Popover>
  </>;
}
