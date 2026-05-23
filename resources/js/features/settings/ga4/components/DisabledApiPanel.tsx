// resources/js/features/settings/ga4/components/DisabledApiPanel.tsx
import { Box, Link, Paper, Typography } from '@mui/material';

export function DisabledApiPanel() {
  return (
    <Paper variant="outlined" sx={{ mt: 2, p: 2, borderColor: 'warning.light' }}>
      <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
        Google Analytics Admin API is disabled
      </Typography>
      <Typography variant="body2" sx={{ mb: 1 }}>
        The Google Analytics Admin API must be enabled in your Google Cloud project.
      </Typography>
      <Box component="ol" sx={{ pl: 2.5, m: 0 }}>
        <Box component="li">
          <Typography variant="body2">
            Click this link:{' '}
            <Link
              href="https://console.developers.google.com/apis/api/analyticsadmin.googleapis.com/overview"
              target="_blank"
              rel="noopener"
            >
              Enable Analytics Admin API
            </Link>{' '}
            — make sure the correct project is selected
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">Click <strong>Enable</strong></Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">Wait ~1 minute, then try again</Typography>
        </Box>
      </Box>
    </Paper>
  );
}
