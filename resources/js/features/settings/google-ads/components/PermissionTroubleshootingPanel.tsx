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
            Check your developer token access level
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Go to the{' '}
            <Link href="https://ads.google.com/aw/apicenter" target="_blank" rel="noopener">
              Google Ads API Center
            </Link>{' '}
            and check your developer token status. If it shows <strong>Test Account</strong>, it can
            only access special test accounts — not real Google Ads accounts.
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75 }}>
            Apply for <strong>Standard Access</strong>, or create a{' '}
            <Link
              href="https://developers.google.com/google-ads/api/docs/first-call/test-accounts"
              target="_blank"
              rel="noopener"
            >
              test manager account
            </Link>{' '}
            and use its Customer ID instead.
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
            Verify the OAuth account has access to the Customer ID
          </Typography>
          <Typography variant="body2" color="text.secondary">
            The Google account used to generate the OAuth Refresh Token must be an admin or user on
            the Customer ID account. In Google Ads, go to{' '}
            <strong>Settings → Account access</strong> and confirm the OAuth account email is listed
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
