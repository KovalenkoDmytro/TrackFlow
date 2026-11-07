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
      <Box sx={{ typography: 'body2', '& p': { mt: 0, mb: 1 } }}>
        {children}
      </Box>
    </Box>
  );
}

export function MetaSetupGuide() {
  return (
    <Accordion disableGutters variant="outlined" sx={{ mb: 3 }}>
      <AccordionSummary expandIcon={<ExpandMoreIcon />} aria-controls="meta-setup-content" id="meta-setup-heading">
        <Box>
          <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>How to find your Pixel ID and Access Token</Typography>
          <Typography variant="body2" color="text.secondary">New to Meta? Start here to get the details needed below.</Typography>
        </Box>
      </AccordionSummary>
      <AccordionDetails>
        <Alert severity="info" sx={{ mb: 2 }}>
          You need a Meta Business account with admin access to a dataset (pixel) in Events Manager.
          A brand-new account has no access token yet — you generate one in the steps below.
        </Alert>
        <Box component="ol" sx={{ pl: 3, mb: 0 }}>
          <Step title="Open your dataset in Events Manager">
            <p>Open <ExternalLink href="https://business.facebook.com/events_manager2/">Meta Events Manager</ExternalLink> and
              choose your business in the account selector at the top right. Go to <strong>Datasets</strong> in the left menu.
              If the list is empty, click <strong>Connect data → Web</strong> and create a dataset first.</p>
          </Step>
          <Step title="Copy the Pixel ID">
            <p>Select your dataset. Its numeric <strong>ID</strong> is shown under the dataset name (also under
              {' '}<strong>Settings → Dataset ID</strong>). Copy it into <strong>Pixel ID</strong> below.</p>
          </Step>
          <Step title="Start the Conversions API setup">
            <p>In the left menu open <strong>Integrations</strong> and click <strong>Conversions API</strong>
              {' '}(under Meta Products; not “Conversions API Gateway”). Pick your dataset and click <strong>Next</strong>.</p>
            <p>Tick at least one event (for example <strong>Purchase</strong>) and click <strong>Continue</strong> on each
              screen. The choices here do not affect TrackFlow — it sends its own events — they only unlock the
              next step. On the <strong>See Instructions</strong> screen, ignore <strong>Send instructions</strong> and click
              {' '}<strong>Continue</strong>.</p>
          </Step>
          <Step title="Generate the Access Token">
            <p>You land on <strong>Using the Conversions API</strong>. Click <strong>Get Started</strong>, then open
              {' '}<strong>Generate an Access Token</strong> in the step list (or scroll down to that section).</p>
            <p>Under <strong>Set up direct integration</strong> leave the default option selected and click
              {' '}<strong>Generate access token</strong>. Copy the token straight away into <strong>Access Token</strong> below.
              Meta shows it only once; if you lose it, generate a new one.</p>
          </Step>
          <Step title="Optional: get a Test Event Code">
            <p>In Events Manager open your dataset → <strong>Test events</strong> tab. The code looks like
              {' '}<code>TEST12345</code>. Paste it into <strong>Test Event Code</strong> to check that events arrive,
              then clear it and save again to send live events.</p>
          </Step>
          <Step title="Save and connect">
            <p>Click <strong>Save &amp; Connect</strong> below.</p>
          </Step>
        </Box>
      </AccordionDetails>
    </Accordion>
  );
}
