// resources/js/features/analytics/components/PlatformDeliveryTable.tsx
import {
  Alert,
  Box,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableRow,
  Tooltip,
  Typography,
} from '@mui/material';
import type { PlatformDeliveryStat, PlatformDeliveryTotals } from '../../../types/api';

interface PlatformDeliveryTableProps {
  stats: PlatformDeliveryStat[];
  totals: PlatformDeliveryTotals;
  loading: boolean;
}

export function PlatformDeliveryTable({ stats, totals, loading }: PlatformDeliveryTableProps) {
  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  const hasNoDeliveries = totals.attempted === 0;

  return (
    <Box>
      {hasNoDeliveries && (
        <Alert severity="info" sx={{ m: 2 }}>
          No events delivered to Meta in this period. Make sure the Meta integration is active.
        </Alert>
      )}

      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell><Typography variant="body2" sx={{ fontWeight: 600 }}>Event</Typography></TableCell>
            <TableCell align="right"><Typography variant="body2" sx={{ fontWeight: 600 }}>Attempted</Typography></TableCell>
            <TableCell align="right"><Typography variant="body2" sx={{ fontWeight: 600 }}>Delivered</Typography></TableCell>
            <TableCell align="right"><Typography variant="body2" sx={{ fontWeight: 600 }}>Failed</Typography></TableCell>
            <TableCell align="right"><Typography variant="body2" sx={{ fontWeight: 600 }}>Pending</Typography></TableCell>
            <TableCell><Typography variant="body2" sx={{ fontWeight: 600 }}>Last error</Typography></TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {stats.map((row) => (
            <TableRow key={row.event}>
              <TableCell>
                <Typography variant="body2" color={row.attempted === 0 ? 'text.disabled' : 'text.primary'}>
                  {row.label}
                </Typography>
              </TableCell>
              <TableCell align="right">
                <Typography
                  variant="body2"
                  sx={{ fontVariantNumeric: 'tabular-nums' }}
                  color={row.attempted === 0 ? 'text.disabled' : 'text.primary'}
                >
                  {row.attempted.toLocaleString()}
                </Typography>
              </TableCell>
              <TableCell align="right">
                <Typography
                  variant="body2"
                  sx={{ fontVariantNumeric: 'tabular-nums' }}
                  color={row.delivered === 0 ? 'text.disabled' : 'text.primary'}
                >
                  {row.delivered.toLocaleString()}
                </Typography>
              </TableCell>
              <TableCell align="right">
                <Typography
                  variant="body2"
                  sx={{ fontVariantNumeric: 'tabular-nums' }}
                  color={row.failed === 0 ? 'text.disabled' : 'error.main'}
                >
                  {row.failed.toLocaleString()}
                </Typography>
              </TableCell>
              <TableCell align="right">
                <Typography
                  variant="body2"
                  sx={{ fontVariantNumeric: 'tabular-nums' }}
                  color={row.pending === 0 ? 'text.disabled' : 'text.primary'}
                >
                  {row.pending.toLocaleString()}
                </Typography>
              </TableCell>
              <TableCell sx={{ maxWidth: 240 }}>
                {row.last_error ? (
                  <Tooltip title={row.last_error}>
                    <Typography
                      variant="body2"
                      color="error.main"
                      noWrap
                      sx={{ maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis' }}
                      title={row.last_error}
                    >
                      {row.last_error}
                    </Typography>
                  </Tooltip>
                ) : (
                  <Typography variant="body2" color="text.disabled">
                    —
                  </Typography>
                )}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
        <TableFooter>
          <TableRow>
            <TableCell><Typography variant="body2" sx={{ fontWeight: 700 }}>Total</Typography></TableCell>
            <TableCell align="right">
              <Typography variant="body2" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                {totals.attempted.toLocaleString()}
              </Typography>
            </TableCell>
            <TableCell align="right">
              <Typography variant="body2" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                {totals.delivered.toLocaleString()}
              </Typography>
            </TableCell>
            <TableCell align="right">
              <Typography variant="body2" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                {totals.failed.toLocaleString()}
              </Typography>
            </TableCell>
            <TableCell align="right">
              <Typography variant="body2" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                {totals.pending.toLocaleString()}
              </Typography>
            </TableCell>
            <TableCell />
          </TableRow>
        </TableFooter>
      </Table>
    </Box>
  );
}
