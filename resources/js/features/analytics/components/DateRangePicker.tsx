// resources/js/features/analytics/components/DateRangePicker.tsx
import { useState } from 'react';
import { Box, Button, TextField, Typography } from '@mui/material';

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

interface DateRangePickerProps {
  startDate: string;
  endDate: string;
  onApply: (start: string, end: string) => void;
}

export function DateRangePicker({ startDate, endDate, onApply }: DateRangePickerProps) {
  const [localStart, setLocalStart] = useState(startDate);
  const [localEnd, setLocalEnd] = useState(endDate);
  const [error, setError] = useState('');

  function handleApply() {
    if (!localStart || !localEnd) {
      setError('Please select both a start and end date.');
      return;
    }
    if (localStart > localEnd) {
      setError('Start date must be on or before end date.');
      return;
    }
    const days = Math.round(
      (new Date(localEnd).getTime() - new Date(localStart).getTime()) / 86400000,
    );
    if (days > 366) {
      setError('Date range may not exceed 366 days.');
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
        slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: todayString() } }}
      />
      <TextField
        label="End date"
        type="date"
        size="small"
        value={localEnd}
        onChange={(e) => { setLocalEnd(e.target.value); setError(''); }}
        slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: localStart, max: todayString() } }}
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
