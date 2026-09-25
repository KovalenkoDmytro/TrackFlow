import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { Accordion, AccordionDetails, AccordionSummary, Alert, Box, Link, Typography } from '@mui/material';

const steps = [
  {
    title: 'Get your Measurement ID and API Secret',
    content: <>Open <Link href="https://analytics.google.com/" target="_blank" rel="noopener noreferrer">Google Analytics</Link> and
      select your store's GA4 property. Go to <strong>Admin → Data streams</strong> and open your website's Web stream.
      Copy its <strong>Measurement ID</strong> (starts with G-) into the field below.
      In the same stream, open <strong>Measurement Protocol API secrets → Create</strong>, name the secret “TrackFlow”,
      and copy its <strong>Secret value</strong> into API Secret. Accept Google's acknowledgment if prompted.
      Both values must come from the same stream. For basic event sending, leave Property ID and all OAuth fields blank,
      then click <strong>Save &amp; Connect</strong>. Continue below if you also want automatic Key Event setup.</>,
  },
  {
    title: 'Find the Property ID and check your Google account',
    content: <>In GA4, open <strong>Admin → Property details</strong> and copy the numeric <strong>Property ID</strong>.
      This is different from the G- Measurement ID and the stream ID. Ask the property administrator to grant
      your Google account the <strong>Editor</strong> role in <strong>Admin → Property access management</strong>.
      Use this same Google account when authorizing in step 6. Access to a Google Cloud project alone does not grant access to GA4.</>,
  },
  {
    title: 'Choose a Google Cloud project and enable the Analytics Admin API',
    content: <>Open <Link href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console</Link>.
      Use the project selector at the top to choose an existing project, or click <strong>New project</strong>,
      enter a name and click <strong>Create</strong>. You can reuse your Google Ads project.
      Keep the same project selected for all remaining Cloud steps. Go to <strong>APIs &amp; Services → Library</strong>,
      search for <strong>Google Analytics Admin API</strong> and click <strong>Enable</strong>.
      If it is already enabled, continue. Google Ads access levels such as Explorer or Standard are not required for GA4.</>,
  },
  {
    title: 'Configure the Google sign-in consent screen',
    content: <>Open <strong>Google Auth Platform → Branding</strong> (or <strong>APIs &amp; Services → OAuth consent screen</strong>).
      Click <strong>Get started</strong> if needed and enter an app name, support email and contact email.
      Under <strong>Audience</strong>, select <strong>External</strong> for personal Gmail accounts or users outside your organization;
      select <strong>Internal</strong> only for users within your Google Workspace organization.
      For External apps in Testing, add the email from step 2 under <strong>Test users</strong>.
      Under <strong>Data Access → Add or remove scopes</strong>, add and save both permissions:
      <Box component="code" sx={{ display: 'block', my: 1 }}>https://www.googleapis.com/auth/analytics.readonly<br />https://www.googleapis.com/auth/analytics.edit</Box>
      If your project already has an OAuth app for Google Ads, reuse it and add these Analytics permissions.</>,
  },
  {
    title: 'Create your OAuth Client ID and Client Secret',
    content: <>Go to <strong>Google Auth Platform → Clients → Create client</strong>.
      Choose <strong>Web application</strong>, enter a name such as “TrackFlow connection”, and under
      <strong> Authorized redirect URIs → Add URI</strong>, enter exactly:
      <Box component="code" sx={{ display: 'block', p: 1.5, my: 1, bgcolor: 'action.hover', borderRadius: 1, userSelect: 'all' }}>https://developers.google.com/oauthplayground</Box>
      Click <strong>Create</strong> and save the Client ID and Client Secret while displayed.
      Paste them into the matching OAuth fields below. You can reuse an existing Web application client
      from this project if it has this redirect URI.</>,
  },
  {
    title: 'Generate a Refresh Token with Analytics permissions',
    content: <>Open <Link href="https://developers.google.com/oauthplayground/" target="_blank" rel="noopener noreferrer">Google OAuth Playground</Link>.
      Click the gear icon, select <strong>Use your own OAuth credentials</strong> and enter the Client ID and
      Client Secret from step 5. Set <strong>Access type</strong> to <strong>Offline</strong>.
      In Step 1, select or enter both Analytics scope URLs from step 4 (separated by a space),
      then click <strong>Authorize APIs</strong>. Sign in with the Google account that has Editor access to your GA4 property
      and approve the requested permissions. In Step 2, click <strong>Exchange authorization code for tokens</strong>.
      Copy <strong>Refresh token</strong> into OAuth Refresh Token below, then click <strong>Save &amp; Connect</strong>.
      A token authorized only for Google Ads does not include the Analytics permissions needed here.</>,
  },
];

export function Ga4SetupGuide({ connected }: { connected: boolean }) {
  return (
    <Accordion defaultExpanded={!connected} disableGutters variant="outlined" sx={{ mb: 3 }}>
      <AccordionSummary expandIcon={<ExpandMoreIcon />} aria-controls="ga4-setup-content" id="ga4-setup-heading">
        <Box>
          <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>How to connect Google Analytics 4 — step-by-step setup</Typography>
          <Typography variant="body2" color="text.secondary">Find your GA4 details and configure Google Cloud for automatic Key Events.</Typography>
        </Box>
      </AccordionSummary>
      <AccordionDetails>
        <Alert severity="info" sx={{ mb: 2 }}>
          Measurement ID and API Secret are enough to send events. Google Cloud and OAuth are needed only
          for automatic Key Event setup, which marks important actions in GA4. For that option, fill in Property ID
          and all three OAuth fields. If someone else manages your Google accounts, share these steps with them.
        </Alert>
        <Box component="ol" sx={{ pl: 3, mb: 0 }}>
          {steps.map((step) => (
            <Box component="li" key={step.title} sx={{ pl: 0.5, mb: 2.5 }}>
              <Typography component="h3" variant="subtitle2" sx={{ mb: 0.75 }}>{step.title}</Typography>
              <Box sx={{ typography: 'body2', '& code': { overflowWrap: 'anywhere' } }}>{step.content}</Box>
            </Box>
          ))}
        </Box>
        <Alert severity="info" sx={{ mb: 2 }}>
          External OAuth apps in Testing issue refresh tokens that expire after 7 days. For ongoing use,
          open Google Auth Platform → Audience → Publish app, complete any verification Google requests,
          then generate a new Refresh Token. Publishing does not grant access to your GA4 property.
        </Alert>
        <Box component="details" sx={{ typography: 'body2', mb: 2 }}>
          <Box component="summary" sx={{ cursor: 'pointer', fontWeight: 600 }}>Having trouble?</Box>
          <Box component="ul" sx={{ pl: 3, '& li': { mb: 1 } }}>
            <li><strong>Google denied access:</strong> Verify the Property ID and the Google account used in step 6. Ask the GA4 administrator to check that account's property access.</li>
            <li><strong>Missing permissions:</strong> Generate a new Refresh Token with the Analytics scopes in step 4. Adding scopes in Cloud Console alone does not update an existing token.</li>
            <li><strong>API disabled:</strong> Enable Google Analytics Admin API in the project that owns your OAuth Client ID.</li>
            <li><strong>redirect_uri_mismatch:</strong> Match the redirect URI in step 5 exactly, without a trailing slash.</li>
            <li><strong>Access blocked while testing:</strong> Add the authorizing account under Audience → Test users, or complete any verification or administrator approval Google requires.</li>
          </Box>
        </Box>
        <Typography variant="caption" color="text.secondary">
          Google documentation:{' '}
          <Link href="https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference" target="_blank" rel="noopener noreferrer">Measurement Protocol</Link>
          {' · '}<Link href="https://developers.google.com/analytics/devguides/config/admin/v1/quickstart" target="_blank" rel="noopener noreferrer">Analytics Admin API</Link>
          {' · '}<Link href="https://developers.google.com/identity/protocols/oauth2" target="_blank" rel="noopener noreferrer">Token lifetime</Link>
        </Typography>
      </AccordionDetails>
    </Accordion>
  );
}
