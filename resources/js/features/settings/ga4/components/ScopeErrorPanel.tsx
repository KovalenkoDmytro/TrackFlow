// resources/js/features/settings/ga4/components/ScopeErrorPanel.tsx
import { Box, Link, Paper, Typography } from '@mui/material';

export function ScopeErrorPanel() {
  return (
    <Paper variant="outlined" sx={{ mt: 2, p: 2, borderColor: 'warning.light' }}>
      <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
        Wrong OAuth scope
      </Typography>
      <Typography variant="body2" sx={{ mb: 1 }}>
        The OAuth Refresh Token must have the <code>analytics.edit</code> scope. Generate a new
        token at{' '}
        <Link href="https://developers.google.com/oauthplayground/" target="_blank" rel="noopener">
          Google OAuth Playground
        </Link>
        :
      </Typography>
      <Box component="ol" sx={{ pl: 2.5, m: 0 }}>
        <Box component="li">
          <Typography variant="body2">
            Click ⚙️ → enable "Use your own OAuth credentials" → enter Client ID and Secret
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">
            Find <strong>Google Analytics Admin API v1</strong> → select <code>analytics.edit</code>
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">
            Click <strong>Authorize APIs</strong> → <strong>Exchange authorization code for tokens</strong>
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">
            Copy the new <code>refresh_token</code> and paste it above
          </Typography>
        </Box>
      </Box>
    </Paper>
  );
}
