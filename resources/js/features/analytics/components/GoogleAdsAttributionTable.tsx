import { Alert, Box, CircularProgress, Table, TableBody, TableCell, TableContainer, TableFooter, TableHead, TableRow, Typography } from '@mui/material';
import { EventDescription } from '../../../components/ui/EventDescription';
import type { AnalyticsSummary } from '../../../types/api';

export function GoogleAdsAttributionTable({ summary, loading }: { summary?: AnalyticsSummary; loading: boolean }) {
  if (loading) return <Box sx={{ p: 4, textAlign: 'center' }}><CircularProgress size={28} /></Box>;
  if (!summary) return <Alert severity="info">Click-matching data is unavailable. Try refreshing the page.</Alert>;
  const attribution = summary.attribution;
  return <>
    <Box sx={{ p: 2 }}>
      <Alert severity="info">
        Matched events have a click ID found in the connected Google Ads account's click report.
        Unverified events have a click ID, but no match has been established. They are excluded from the matched total.
        These are storefront actions, not unique visitors or Google Ads conversion totals.
      </Alert>
      <Typography variant="body2" sx={{ mt: 1.5 }}>
        Google click reports can include invalid clicks. A match confirms the click ID belongs to this account;
        it does not prove a human visit or a billable click. Copied links can carry the same ID.
        Traffic without a captured GCLID is not included.
      </Typography>
      {!attribution?.connected ? <Alert severity="warning" sx={{ mt: 2 }}>Connect Google Ads to match events to your advertising account.</Alert>
        : !attribution.last_checked_at ? <Alert severity="info" sx={{ mt: 2 }}>Waiting for the first click-report sync. All tagged events remain unverified until matching runs.</Alert>
          : <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
            Account {attribution.customer_id} · Last successful report sync: {attribution.last_checked_at} UTC.
            {' '}{attribution.checked_days} report days checked. Recent reports refresh hourly; retained history refreshes daily.
          </Typography>}
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
        Reports can arrive late, and clicks outside Google's 90-day reporting window cannot be checked.
        Unverified does not mean organic traffic. Events collected before connection can be matched later.
      </Typography>
    </Box>
    <TableContainer>
      <Table size="small">
        <TableHead><TableRow><TableCell>Event</TableCell><TableCell align="right">Matched to Google Ads</TableCell><TableCell align="right">Unverified</TableCell></TableRow></TableHead>
        <TableBody>{[...summary.counts].sort((a, b) => b.count - a.count).map(row => <TableRow key={row.event}>
          <TableCell><Typography variant="body2" sx={{ fontWeight: 600 }}>{row.label}</Typography><EventDescription event={row.event} /></TableCell>
          <TableCell align="right">{row.count.toLocaleString()}</TableCell><TableCell align="right">{(row.unverified ?? 0).toLocaleString()}</TableCell>
        </TableRow>)}</TableBody>
        <TableFooter><TableRow><TableCell>Total events</TableCell><TableCell align="right">{summary.total.toLocaleString()}</TableCell><TableCell align="right">{(summary.unverified_total ?? 0).toLocaleString()}</TableCell></TableRow></TableFooter>
      </Table>
    </TableContainer>
  </>;
}
