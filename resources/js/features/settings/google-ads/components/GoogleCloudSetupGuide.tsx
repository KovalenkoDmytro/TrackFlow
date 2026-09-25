import type { ReactNode } from 'react';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { Accordion, AccordionDetails, AccordionSummary, Alert, Box, Link, Typography } from '@mui/material';

function ExternalLink({ href, children }: { href: string; children: ReactNode }) {
  return <Link href={href} target="_blank" rel="noopener noreferrer">{children}</Link>;
}

function Step({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Box component="li" sx={{ pl: 0.5, mb: 2.5 }}>
      <Typography component="h3" variant="subtitle2" sx={{ mb: 0.75 }}>{title}</Typography>
      <Box sx={{ typography: 'body2', '& p': { mt: 0, mb: 1 }, '& code': { overflowWrap: 'anywhere' } }}>
        {children}
      </Box>
    </Box>
  );
}

export function GoogleCloudSetupGuide({ connected }: { connected: boolean }) {
  return (
    <Accordion defaultExpanded={!connected} disableGutters variant="outlined" sx={{ mb: 3 }}>
      <AccordionSummary expandIcon={<ExpandMoreIcon />} aria-controls="google-cloud-setup-content" id="google-cloud-setup-heading">
        <Box>
          <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>How to connect Google Ads — step-by-step setup</Typography>
          <Typography variant="body2" color="text.secondary">New to Google Cloud? Start here to get the details needed below.</Typography>
        </Box>
      </AccordionSummary>
      <AccordionDetails>
        <Alert severity="info" sx={{ mb: 2 }}>
          You need permission to manage a Google Cloud project and Standard or Admin access to your
          Google Ads account. If someone else manages these, share these steps with them.
          Developer Tokens are no longer needed for this connection.
        </Alert>
        <Box component="ol" sx={{ pl: 3, mb: 0 }}>
          <Step title="Choose one Google Cloud project">
            <p>Open <ExternalLink href="https://console.cloud.google.com/">Google Cloud Console</ExternalLink>.
              Use the project selector at the top to select your existing project, or choose <strong>New project</strong>,
              give it a name and click <strong>Create</strong>. Keep this same project selected for all Cloud steps.</p>
          </Step>
          <Step title="Enable Google Ads API and check access">
            <p>Go to <strong>APIs &amp; Services → Library</strong>, search for <strong>Google Ads API</strong>
              {' '}and click <strong>Enable</strong>. If its status is already Enabled, continue.</p>
            <p>On the API details page, find <strong>Access levels → Manage</strong>, or open the{' '}
              <ExternalLink href="https://console.cloud.google.com/google-ads-apis/overview">Google Ads API access page</ExternalLink>.
              {' '}If you see <strong>Test</strong>, open <strong>Upgrade access level</strong> (sometimes called
              {' '}Apply for next access level) and apply for <strong>Explorer</strong>. Wait for approval before connecting a real account.
              {' '}If you already have <strong>Explorer, Basic or Standard</strong>, continue.</p>
          </Step>
          <Step title="Set up the Google sign-in consent screen">
            <p>Open <strong>Google Auth Platform → Branding</strong> (also accessible through
              {' '}<strong>APIs &amp; Services → OAuth consent screen</strong>). Click <strong>Get started</strong>
              {' '}if prompted. Enter an app name such as “My Store Analytics”, your support email and contact email.</p>
            <p>Under <strong>Audience</strong>, choose <strong>External</strong> for personal Gmail accounts or
              users outside your organization. Use <strong>Internal</strong> only when everyone signing in belongs
              to your Google Workspace organization. For External apps in Testing, add the Google Ads user's
              email under <strong>Test users</strong>.</p>
            <p>Under <strong>Data Access → Add or remove scopes</strong>, add
              {' '}<code>https://www.googleapis.com/auth/adwords</code> and save.</p>
            <Alert severity="info">
              External apps in Testing issue refresh tokens that expire after 7 days. For ongoing tracking,
              use Audience → Publish app to move to In production, complete any verification Google requests,
              then generate a new token. OAuth publishing and Google Ads API access are separate settings.
            </Alert>
          </Step>
          <Step title="Create your Client ID and Client Secret">
            <p>Open <strong>Google Auth Platform → Clients → Create client</strong>. Select
              {' '}<strong>Web application</strong> and give it a name, such as “TrackFlow connection”.</p>
            <p>Under <strong>Authorized redirect URIs → Add URI</strong>, enter this exact address:</p>
            <Box component="code" sx={{ display: 'block', p: 1.5, bgcolor: 'action.hover', borderRadius: 1, mb: 1, userSelect: 'all' }}>
              https://developers.google.com/oauthplayground
            </Box>
            <p>Click <strong>Create</strong>. Copy the <strong>Client ID</strong> and <strong>Client Secret</strong>
              {' '}into the matching OAuth fields below. Save the secret when it is displayed; Google may not show it again.</p>
          </Step>
          <Step title="Generate your Refresh Token">
            <p>Open <ExternalLink href="https://developers.google.com/oauthplayground/">Google OAuth Playground</ExternalLink>.
              {' '}Click the gear icon, enable <strong>Use your own OAuth credentials</strong>, and enter the
              Client ID and Client Secret from step 4. Set <strong>Access type</strong> to <strong>Offline</strong>.</p>
            <p>In Step 1, enter <code>https://www.googleapis.com/auth/adwords</code> in the scope field,
              then click <strong>Authorize APIs</strong>. Sign in with the Google account that has access to
              your advertising account and approve the requested access.</p>
            <p>In Step 2, click <strong>Exchange authorization code for tokens</strong>. Copy
              {' '}<strong>Refresh token</strong> into <strong>OAuth Refresh Token</strong> below.
              The Access token is a different value and will not work in this field.</p>
          </Step>
          <Step title="Enter your Google Ads account and connect">
            <p>Open your advertising account in <ExternalLink href="https://ads.google.com/">Google Ads</ExternalLink>
              {' '}and copy its 10-digit account number into <strong>Customer ID</strong> below.
              If you access it through a manager account, enter that manager's ID in <strong>MCC Customer ID</strong>.
              Otherwise, leave MCC blank.</p>
            <p>Confirm the Google account used in step 5 has Standard or Admin access in
              {' '}<strong>Google Ads → Admin → Access and security</strong>, directly or through the linked manager.
              Then click <strong>Save &amp; Connect</strong> below.</p>
          </Step>
        </Box>
        <Box component="details" sx={{ typography: 'body2', mb: 2 }}>
          <Box component="summary" sx={{ cursor: 'pointer', fontWeight: 600 }}>Having trouble?</Box>
          <Box component="ul" sx={{ pl: 3, '& li': { mb: 1 } }}>
            <li><strong>redirect_uri_mismatch:</strong> Check that the redirect URI in step 4 matches exactly, without a trailing slash.</li>
            <li><strong>Access blocked while testing:</strong> Add the email you are signing in with to Audience → Test users. If Google requires verification or administrator approval, complete that first.</li>
            <li><strong>Permission denied:</strong> Check both the Cloud project's API access level and the Google account's access to the Customer ID. A token from another Google account will not grant access.</li>
            <li><strong>Connection stops after a week:</strong> Check the OAuth publishing status in step 3 and generate a new Refresh Token after publishing.</li>
          </Box>
        </Box>
        <Typography variant="caption" color="text.secondary">
          Google documentation:{' '}
          <ExternalLink href="https://developers.google.com/google-ads/api/docs/oauth/cloud-project">Cloud setup</ExternalLink>
          {' · '}<ExternalLink href="https://developers.google.com/google-ads/api/docs/api-policy/developer-token">API access changes</ExternalLink>
          {' · '}<ExternalLink href="https://support.google.com/cloud/answer/15544987">Google Auth Platform</ExternalLink>
          {' · '}<ExternalLink href="https://developers.google.com/identity/protocols/oauth2">Token lifetime</ExternalLink>
        </Typography>
      </AccordionDetails>
    </Accordion>
  );
}
