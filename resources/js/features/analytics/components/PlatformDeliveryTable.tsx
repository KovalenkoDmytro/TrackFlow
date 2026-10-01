import { EventDescription } from '../../../components/ui/EventDescription';
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
  TableContainer,
  TableRow,
  Tooltip,
  Typography,
} from '@mui/material';
import type { PlatformDeliveryStat, PlatformDeliveryTotals, PlatformIntegrationState } from '../../../types/api';

interface PlatformDeliveryTableProps {
  stats: PlatformDeliveryStat[];
  totals: PlatformDeliveryTotals;
  loading: boolean;
  platformLabel?: string;
  integration?: PlatformIntegrationState;
  /** Google Ads only: show the "Other account" column. */
  showOtherAccount?: boolean;
}

const OTHER_ACCOUNT_TOOLTIP =
  'The ad click came from a Google Ads account that is not connected. Google only accepts the conversion in the account that owns the click.';

export function PlatformDeliveryTable({ stats, totals, loading, platformLabel = 'Meta', integration, showOtherAccount = false }: PlatformDeliveryTableProps) {
  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  const hasNoDeliveries = totals.attempted === 0;

  let emptyMessage = `No events delivered to ${platformLabel} in this period. Make sure the ${platformLabel} integration is active.`;
  if (integration && !integration.active) {
    emptyMessage = `${platformLabel} is not connected. Connect it in Settings to start sending events.`;
  } else if (integration && integration.active_mappings === 0) {
    emptyMessage = `${platformLabel} is connected but has no event mappings. Reconnect it in Settings or contact support.`;
  }

  return (
    <Box>
      {hasNoDeliveries && (
        <Alert severity="info" sx={{ m: 2 }}>
          {emptyMessage}
        </Alert>
      )}

      <TableContainer sx={{ width: '100%', overflowX: 'auto' }}><Table size="small" sx={{ minWidth: { xs: 560, sm: 740 } }}>
        <TableHead>
          <TableRow sx={{ '& th': { bgcolor: '#f8f9fc', fontWeight: 700, whiteSpace: 'nowrap' } }}>
            <TableCell>Event</TableCell>
            <TableCell align="right">Attempted</TableCell>
            <TableCell align="right">Delivered</TableCell>
            <TableCell align="right">Failed</TableCell>
            <TableCell align="right">Pending</TableCell>
            {showOtherAccount && (
              <TableCell align="right">
                <Tooltip title={OTHER_ACCOUNT_TOOLTIP}>
                  <span>Other account</span>
                </Tooltip>
              </TableCell>
            )}
            <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>Last error</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {stats.map((row) => (
            <TableRow key={row.event} hover>
              <TableCell>
                <Typography variant="body2" color={row.attempted === 0 ? 'text.disabled' : 'text.primary'}>
                  {row.label}
                </Typography>
                <EventDescription event={row.event} />
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
              {showOtherAccount && (
                <TableCell align="right">
                  <Typography
                    variant="body2"
                    sx={{ fontVariantNumeric: 'tabular-nums' }}
                    color={row.other_account === 0 ? 'text.disabled' : 'text.primary'}
                  >
                    {row.other_account.toLocaleString()}
                  </Typography>
                </TableCell>
              )}
              <TableCell sx={{ maxWidth: 200, display: { xs: 'none', sm: 'table-cell' } }}>
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
          <TableRow sx={{ bgcolor: '#fafbfe', '& td': { fontWeight: 750 } }}>
            <TableCell>Total</TableCell>
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
            {showOtherAccount && (
              <TableCell align="right">
                <Typography variant="body2" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
                  {totals.other_account.toLocaleString()}
                </Typography>
              </TableCell>
            )}
            <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }} />
          </TableRow>
        </TableFooter>
      </Table></TableContainer>
    </Box>
  );
}
