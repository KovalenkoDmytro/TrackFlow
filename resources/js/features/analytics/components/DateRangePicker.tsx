// resources/js/features/analytics/components/DateRangePicker.tsx
import { useState } from 'react';
import { Box, Button, TextField, Typography } from '@mui/material';

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

interface DateRangePickerProps {
  startDate: string;
  endDate: string;
  onApply: (start: string, end: string) => void;
  retentionDays: number;
}

export function DateRangePicker({ startDate, endDate, onApply, retentionDays }: DateRangePickerProps) {
  const [localStart, setLocalStart] = useState(startDate);
  const [localEnd, setLocalEnd] = useState(endDate);
  const [error, setError] = useState('');

  function handleApply() {
    const today = localDateString();
    const earliestDate = dateDaysAgo(retentionDays);

    if (!localStart || !localEnd) {
      setError('Please select both a start and end date.');
      return;
    }
    if (localStart > localEnd) {
      setError('Start date must be on or before end date.');
      return;
    }
    if (localStart < earliestDate || localEnd < earliestDate) {
      setError(`Data is available only for the last ${retentionDays} days.`);
      return;
    }
    if (localStart > today || localEnd > today) {
      setError('Future dates cannot be selected.');
      return;
    }
    setError('');
    onApply(localStart, localEnd);
  }

  return (
    <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, flexWrap: 'wrap' }}>
      <TextField
        label="Start date"
        type="date"
        size="small"
        value={localStart}
        onChange={(e) => { setLocalStart(e.target.value); setError(''); }}
        slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: dateDaysAgo(retentionDays), max: localDateString() } }}
      />
      <TextField
        label="End date"
        type="date"
        size="small"
        value={localEnd}
        onChange={(e) => { setLocalEnd(e.target.value); setError(''); }}
        slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: localStart || dateDaysAgo(retentionDays), max: localDateString() } }}
      />
      <Button variant="contained" size="small" onClick={handleApply} sx={{ mt: 0.5 }}>
        Apply
      </Button>
      {error && (
        <Typography variant="caption" color="error" sx={{ alignSelf: 'center' }}>
          {error}
        </Typography>
      )}
    </Box>
  );
}
