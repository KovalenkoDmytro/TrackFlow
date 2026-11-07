import { Alert, AlertTitle } from '@mui/material';

interface OtherGoogleAdsAccountNoticeProps {
  otherAccount: number;
  attempted: number;
  customerId?: string | null;
}

export function OtherGoogleAdsAccountNotice({ otherAccount, attempted, customerId }: OtherGoogleAdsAccountNoticeProps) {
  if (otherAccount <= 0 || attempted <= 0) return null;

  return (
    <Alert severity="info" sx={{ mb: 2 }}>
      <AlertTitle>Some conversions come from another Google Ads account</AlertTitle>
      {otherAccount.toLocaleString()} of {attempted.toLocaleString()} Google Ads conversions came from clicks in another
      Google Ads account (not connected).{customerId ? ` The connected account is ${customerId}.` : ''} Google only
      accepts a conversion in the account that owns the click. If your ads run in another account, for example an older
      one, connect that account to credit these conversions.
    </Alert>
  );
}
