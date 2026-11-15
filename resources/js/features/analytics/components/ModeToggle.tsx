import { ToggleButton, ToggleButtonGroup } from '@mui/material';
import type { AnalyticsMode } from '../../../types/api';

interface ModeToggleProps {
  mode: AnalyticsMode;
  onChange: (mode: AnalyticsMode) => void;
}

export function ModeToggle({ mode, onChange }: ModeToggleProps) {
  return <ToggleButtonGroup exclusive value={mode} onChange={(_, next: AnalyticsMode | null) => { if (next) onChange(next); }} aria-label="Date selection mode" size="small" sx={{ p: .5, bgcolor: '#f2f5f0', borderRadius: '12px', alignSelf: 'flex-start', '& .MuiToggleButtonGroup-grouped': { border: 0, borderRadius: '8px !important', px: 2, py: .65, color: '#7c8d80', fontSize: 12, fontWeight: 700, textTransform: 'none', '&.Mui-selected': { bgcolor: '#fff', color: '#315e46', boxShadow: '0 1px 4px rgba(29,61,42,.1)' }, '&.Mui-selected:hover': { bgcolor: '#fff' } } }}>
    <ToggleButton value="single_day">Single day</ToggleButton>
    <ToggleButton value="range">Date range</ToggleButton>
  </ToggleButtonGroup>;
}
