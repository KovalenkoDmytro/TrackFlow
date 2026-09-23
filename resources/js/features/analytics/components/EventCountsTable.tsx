import { useMemo, useState } from 'react';
import { Box, CircularProgress, Table, TableBody, TableCell, TableContainer, TableFooter, TableHead, TableRow, TableSortLabel, Typography } from '@mui/material';
import type { EventCount } from '../../../types/api';

interface EventCountsTableProps {
  counts: EventCount[];
  total: number;
  loading: boolean;
}

export function EventCountsTable({ counts, total, loading }: EventCountsTableProps) {
  const [sortBy, setSortBy] = useState<'event' | 'count'>('count');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

  const rows = useMemo(() => [...counts].sort((a, b) => {
    const compared = sortBy === 'count' ? a.count - b.count : a.label.localeCompare(b.label);
    return sortDirection === 'asc' ? compared : -compared;
  }), [counts, sortBy, sortDirection]);

  function requestSort(field: 'event' | 'count') {
    if (sortBy === field) setSortDirection((direction) => direction === 'asc' ? 'desc' : 'asc');
    else { setSortBy(field); setSortDirection(field === 'count' ? 'desc' : 'asc'); }
  }

  if (loading) return <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}><CircularProgress size={28} /></Box>;

  return <TableContainer sx={{ maxHeight: 520 }}>
    <Table size="small" stickyHeader>
      <TableHead><TableRow>
        <TableCell sx={{ bgcolor: '#f8f9fc', fontWeight: 700 }}><TableSortLabel active={sortBy === 'event'} direction={sortBy === 'event' ? sortDirection : 'asc'} onClick={() => requestSort('event')}>Event</TableSortLabel></TableCell>
        <TableCell align="right" sx={{ bgcolor: '#f8f9fc', fontWeight: 700, width: 180 }}><TableSortLabel active={sortBy === 'count'} direction={sortBy === 'count' ? sortDirection : 'desc'} onClick={() => requestSort('count')}>Events</TableSortLabel></TableCell>
      </TableRow></TableHead>
      <TableBody>
        {rows.length === 0 ? <TableRow><TableCell colSpan={2} sx={{ py: 5, textAlign: 'center' }}><Typography variant="body2" color="text.secondary">No events found for this date.</Typography></TableCell></TableRow> : rows.map((row) => {
          const share = total ? Math.round((row.count / total) * 100) : 0;
          return <TableRow key={row.event} hover sx={{ '&:last-child td': { borderBottom: 0 } }}>
            <TableCell sx={{ py: 1.5 }}><Box sx={{ display: 'flex', alignItems: 'center', gap: 1.25 }}><Box sx={{ width: 7, height: 7, borderRadius: '50%', bgcolor: row.count ? 'primary.main' : '#c9cdda', flexShrink: 0 }} /><Typography variant="body2" sx={{ fontWeight: 550 }} color={row.count === 0 ? 'text.secondary' : 'text.primary'}>{row.label}</Typography></Box></TableCell>
            <TableCell align="right"><Box sx={{ display: 'inline-flex', minWidth: 100, alignItems: 'center', justifyContent: 'flex-end', gap: 1.25 }}><Box sx={{ width: 56, height: 5, borderRadius: 9, bgcolor: '#eceef5', overflow: 'hidden' }}><Box sx={{ width: `${share}%`, height: '100%', borderRadius: 9, bgcolor: 'primary.main', opacity: row.count ? .8 : .2 }} /></Box><Typography variant="body2" sx={{ minWidth: 44, textAlign: 'right', fontWeight: 650, fontVariantNumeric: 'tabular-nums' }} color={row.count === 0 ? 'text.disabled' : 'text.primary'}>{row.count.toLocaleString()}</Typography></Box></TableCell>
          </TableRow>;
        })}
      </TableBody>
      <TableFooter><TableRow sx={{ bgcolor: '#fafbfe' }}><TableCell sx={{ fontWeight: 750 }}>Total events</TableCell><TableCell align="right" sx={{ fontWeight: 750, fontVariantNumeric: 'tabular-nums' }}>{total.toLocaleString()}</TableCell></TableRow></TableFooter>
    </Table>
  </TableContainer>;
}
