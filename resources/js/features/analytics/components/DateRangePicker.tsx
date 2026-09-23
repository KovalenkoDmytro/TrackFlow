import { useState } from 'react';
import { Alert, Box, Button, Popover, Stack, Typography } from '@mui/material';
import CalendarMonthRoundedIcon from '@mui/icons-material/CalendarMonthRounded';
import { DayPicker, type DateRange } from 'react-day-picker';
import 'react-day-picker/style.css';
import '../../../../css/analytics-calendar.css';

function isoDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function fromIso(value: string): Date {
  const [year, month, day] = value.split('-').map(Number);
  return new Date(year, month - 1, day);
}

function startOfToday(): Date {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return today;
}

function dateDaysAgo(days: number): Date {
  const date = startOfToday();
  date.setDate(date.getDate() - days);
  return date;
}

function displayDate(value: string): string {
  return fromIso(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

interface DateRangePickerProps {
  startDate: string;
  endDate: string;
  onApply: (start: string, end: string) => void;
  retentionDays: number;
}

export function DateRangePicker({ startDate, endDate, onApply, retentionDays }: DateRangePickerProps) {
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const [range, setRange] = useState<DateRange | undefined>({ from: fromIso(startDate), to: fromIso(endDate) });
  const [error, setError] = useState('');
  const today = startOfToday();
  const earliest = dateDaysAgo(retentionDays);
  const rangeLabel = range?.from
    ? `${displayDate(isoDate(range.from))} – ${range.to ? displayDate(isoDate(range.to)) : 'Select end date'}`
    : 'Choose date range';

  function choosePreset(days: number) {
    const to = startOfToday();
    const from = new Date(to);
    from.setDate(from.getDate() - Math.min(days - 1, retentionDays - 1));
    setRange({ from, to });
    setError('');
  }

  function applyRange() {
    if (!range?.from || !range.to) {
      setError('Choose both a start and end date.');
      return;
    }
    if (range.from > range.to) {
      setError('Start date must be on or before end date.');
      return;
    }
    setError('');
    onApply(isoDate(range.from), isoDate(range.to));
    setAnchor(null);
  }

  return <>
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.25} sx={{ alignItems: { xs: 'stretch', sm: 'center' } }}>
      <Button variant="outlined" startIcon={<CalendarMonthRoundedIcon />} onClick={(event) => setAnchor(event.currentTarget)} sx={{ justifyContent: 'flex-start', minWidth: { sm: 260 } }}>
        {rangeLabel}
      </Button>
      <Typography variant="caption" color="text.secondary">Select a preset or choose dates</Typography>
    </Stack>
    <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }} transformOrigin={{ vertical: 'top', horizontal: 'left' }} slotProps={{ paper: { sx: { maxWidth: 'calc(100vw - 24px)' } } }}>
      <Box sx={{ p: { xs: 1.5, sm: 2 } }}>
        <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>Date range</Typography>
        <Stack direction="row" spacing={.75} sx={{ mb: 1, flexWrap: 'wrap', rowGap: .75 }}>
          {[7, 30, 90].filter((days) => days <= retentionDays).map((days) => <Button key={days} size="small" variant="text" onClick={() => choosePreset(days)}>Last {days} days</Button>)}
        </Stack>
        <DayPicker
          className="trackflow-calendar"
          mode="range"
          selected={range}
          onSelect={(nextRange) => { setRange(nextRange); setError(''); }}
          disabled={{ before: earliest, after: today }}
          numberOfMonths={1}
          defaultMonth={range?.from}
        />
        {error && <Alert severity="error" sx={{ mt: 1 }}>{error}</Alert>}
        <Box sx={{ mt: 1.5, pt: 1.5, borderTop: '1px solid', borderColor: 'divider', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1 }}>
          <Typography variant="caption" color="text.secondary">Data retained for {retentionDays} days</Typography>
          <Button variant="contained" size="small" onClick={applyRange} disabled={!range?.from || !range.to}>Apply dates</Button>
        </Box>
      </Box>
    </Popover>
  </>;
}
