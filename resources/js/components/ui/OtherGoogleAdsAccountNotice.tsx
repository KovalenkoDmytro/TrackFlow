import { Alert, AlertTitle } from '@mui/material';

export function OtherGoogleAdsAccountNotice({ customerId }: { customerId?: string | null }) {
  return (
    <Alert severity="warning" sx={{ mb: 2 }}>
      <AlertTitle>These clicks may come from a different Google Ads account</AlertTitle>
      None of the Google click IDs captured on your store belong to the connected Google Ads account
      {customerId ? ` (${customerId})` : ''}. Your ads are probably running in another Google Ads account, for example
      an older one or the account for a different country or store. Please check every Google Ads account linked to
      this store, including the Google &amp; YouTube channel in Shopify, and connect the one that runs your ads.
      Until then, Google Ads cannot credit conversions from this store.
    </Alert>
  );
}
