import { Box, CircularProgress, Typography } from '@mui/material';
import DonutLargeRoundedIcon from '@mui/icons-material/DonutLargeRounded';
import type { EventCount } from '../../../types/api';

const COLORS = ['#326a4b', '#75a878', '#b5ce9e', '#d6c58c', '#9bb6aa', '#d49f7f', '#738e79', '#c4d6bd'];

interface EventCountsChartProps {
  counts: EventCount[];
  total: number;
  loading: boolean;
}

export function EventCountsChart({ counts, total, loading }: EventCountsChartProps) {
  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', py: 8 }}><CircularProgress size={28} /></Box>;

  const rows = [...counts].filter((row) => row.count > 0).sort((a, b) => b.count - a.count);
  if (rows.length === 0 || total <= 0) return <Box sx={{ py: 8, px: 3, textAlign: 'center' }}><Box sx={{ width: 52, height: 52, display: 'grid', placeItems: 'center', borderRadius: '18px', bgcolor: '#eff5ed', color: '#6c9875', mx: 'auto', mb: 2 }}><DonutLargeRoundedIcon /></Box><Typography sx={{ fontWeight: 750 }}>No events for this period</Typography><Typography color="text.secondary" variant="body2" sx={{ mt: 1 }}>Choose another date or date range to see your event breakdown.</Typography></Box>;

  let offset = 0;
  const stops = rows.map((row, index) => {
    const start = offset;
    offset += row.count / total * 100;
    return `${COLORS[index % COLORS.length]} ${start}% ${offset}%`;
  });

  return <Box sx={{ p: { xs: 2, sm: 3, md: 3.5 } }}>
    <Box sx={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 2, flexWrap: 'wrap', mb: 3 }}>
      <Box><Typography sx={{ fontSize: 11, fontWeight: 800, letterSpacing: '.12em', color: '#819288', mb: .75 }}>EVENT INSIGHTS</Typography><Typography variant="h6">Your events at a glance</Typography><Typography variant="body2" color="text.secondary" sx={{ mt: .5 }}>A breakdown of activity for your selected period.</Typography></Box>
      <Box sx={{ px: 1.5, py: .75, borderRadius: '99px', bgcolor: '#f2f6f0', color: '#66836a', fontSize: 11, fontWeight: 750, whiteSpace: 'nowrap' }}>{rows.length} {rows.length === 1 ? 'event type' : 'event types'}</Box>
    </Box>
    <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'minmax(250px, .85fr) minmax(0, 1.4fr)' }, gap: 2 }}>
      <Box sx={{ borderRadius: '16px', bgcolor: '#f3f7f0', p: 3, minHeight: 300, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
        <Typography sx={{ alignSelf: 'flex-start', fontSize: 11, fontWeight: 800, color: '#78907c', letterSpacing: '.09em' }}>TOTAL ACTIVITY</Typography>
        <Box aria-hidden="true" sx={{ position: 'relative', width: 210, height: 210, mt: 2, borderRadius: '50%', background: `conic-gradient(${stops.join(', ')})`, display: 'grid', placeItems: 'center', boxShadow: '0 12px 25px rgba(50, 106, 75, .12)' }}>
          <Box sx={{ width: 158, height: 158, borderRadius: '50%', bgcolor: '#f3f7f0', display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center' }}><Typography sx={{ fontSize: 34, lineHeight: 1.1, fontWeight: 800, letterSpacing: '-.05em', fontVariantNumeric: 'tabular-nums' }}>{total.toLocaleString()}</Typography><Typography sx={{ mt: .5, color: '#849489', fontSize: 12, fontWeight: 600 }}>total events</Typography></Box>
        </Box>
        <Typography sx={{ mt: 2.5, fontSize: 12, color: '#819288', textAlign: 'center' }}>Events collected in this period</Typography>
      </Box>
      <Box sx={{ border: '1px solid #edf0eb', borderRadius: '16px', p: { xs: 2, sm: 2.5 } }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 2, mb: 2 }}><Typography sx={{ fontSize: 13, fontWeight: 750 }}>Breakdown by event</Typography><Typography sx={{ color: '#98a69b', fontSize: 11 }}>Share of total</Typography></Box>
        <Box component="ul" aria-label={`Event breakdown, ${total.toLocaleString()} total events`} sx={{ listStyle: 'none', m: 0, p: 0 }}>
          {rows.map((row, index) => {
            const share = row.count / total * 100;
            return <Box component="li" key={row.event} sx={{ py: 1.5, borderTop: '1px solid #f0f3ef' }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 2, mb: 1 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25, minWidth: 0 }}><Box sx={{ width: 10, height: 10, borderRadius: '4px', bgcolor: COLORS[index % COLORS.length], flexShrink: 0 }} /><Typography variant="body2" sx={{ fontWeight: 650, overflowWrap: 'anywhere' }}>{row.label}</Typography></Box>
                <Box sx={{ display: 'flex', alignItems: 'baseline', gap: 1, whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}><Typography variant="body2" sx={{ fontWeight: 750 }}>{row.count.toLocaleString()}</Typography><Typography sx={{ color: '#95a398', fontSize: 11, minWidth: 44, textAlign: 'right' }}>{share.toLocaleString(undefined, { maximumFractionDigits: 1 })}%</Typography></Box>
              </Box>
              <Box aria-hidden="true" sx={{ height: 6, bgcolor: '#f0f4ef', borderRadius: 10, overflow: 'hidden', ml: '20px' }}><Box sx={{ width: `${share}%`, height: '100%', borderRadius: 10, bgcolor: COLORS[index % COLORS.length] }} /></Box>
            </Box>;
          })}
        </Box>
      </Box>
    </Box>
  </Box>;
}
