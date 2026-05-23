// resources/js/features/analytics/components/EventCountsTable.tsx
import {
  Box,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import type { EventCount } from '../../../types/api';

interface EventCountsTableProps {
  counts: EventCount[];
  total: number;
  loading: boolean;
}

export function EventCountsTable({ counts, total, loading }: EventCountsTableProps) {
  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  return (
    <Table size="small">
      <TableHead>
        <TableRow>
          <TableCell><Typography variant="body2" sx={{ fontWeight: 600 }}>Event</Typography></TableCell>
          <TableCell align="right"><Typography variant="body2" sx={{ fontWeight: 600 }}>Count</Typography></TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {counts.map((row) => (
          <TableRow key={row.event}>
            <TableCell>
              <Typography variant="body2" color={row.count === 0 ? 'text.disabled' : 'text.primary'}>
                {row.label}
              </Typography>
            </TableCell>
            <TableCell align="right">
              <Typography
                variant="body2"
                sx={{ fontVariantNumeric: 'tabular-nums' }}
                color={row.count === 0 ? 'text.disabled' : 'text.primary'}
              >
                {row.count.toLocaleString()}
              </Typography>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
      <TableFooter>
        <TableRow>
          <TableCell><Typography variant="body2" sx={{ fontWeight: 700 }}>Total</Typography></TableCell>
          <TableCell align="right">
            <Typography variant="body2" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
              {total.toLocaleString()}
            </Typography>
          </TableCell>
        </TableRow>
      </TableFooter>
    </Table>
  );
}
