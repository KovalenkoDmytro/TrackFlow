// resources/js/features/settings/google-ads/components/PermissionTroubleshootingPanel.tsx
import { Box, Link, Paper, Typography } from '@mui/material';

export function PermissionTroubleshootingPanel() {
  return (
    <Paper variant="outlined" sx={{ mt: 2, p: 2.5, borderColor: 'warning.light' }}>
      <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 2 }}>
        Troubleshooting — Permission Denied
      </Typography>
      <Box component="ol" sx={{ pl: 2.5, m: 0, display: 'flex', flexDirection: 'column', gap: 2.5 }}>
        <Box component="li">
          <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
            Check your Google Cloud project's API access
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Open the{' '}
            <Link href="https://console.cloud.google.com/google-ads-apis/overview" target="_blank" rel="noopener">
              Google Ads API Overview
            </Link>{' '}
            in the project that owns your OAuth Client ID. Enable Google Ads API if needed.
            If the access level is <strong>Test</strong>, open <strong>Upgrade access level</strong>
            {' '}and apply for <strong>Explorer</strong> to connect a real advertising account.
            Explorer, Basic and Standard access support production accounts.
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
            Verify the OAuth account has access to the Customer ID
          </Typography>
          <Typography variant="body2" color="text.secondary">
            The Google account used to generate the OAuth Refresh Token must have Standard or Admin access to
            the Customer ID account. In Google Ads, go to{' '}
            <strong>Admin → Access and security</strong> and confirm the OAuth account email is listed
            there.
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
            Confirm the Customer ID is linked to your MCC (if using an MCC Customer ID)
          </Typography>
          <Typography variant="body2" color="text.secondary">
            The Customer ID must be a sub-account under the MCC. In your MCC account, verify the
            Customer ID appears as a linked account.
          </Typography>
        </Box>
      </Box>
    </Paper>
  );
}
