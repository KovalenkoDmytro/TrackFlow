import { Typography } from '@mui/material';

const descriptions: Record<string, string> = {
  purchase: 'A customer completed an order.',
  add_to_cart: 'A visitor added a product to their cart.',
  begin_checkout: 'A customer started checking out.',
  add_payment_info: 'A customer submitted their payment details at checkout.',
  add_shipping_info: 'A customer submitted their delivery details at checkout.',
  view_item: 'A visitor viewed a product page.',
  view_cart: 'A visitor viewed their shopping cart.',
  search: 'A visitor searched your store.',
  remove_from_cart: 'A visitor removed a product from their cart.',
};

export function EventDescription({ event }: { event: string }) {
  const description = Object.prototype.hasOwnProperty.call(descriptions, event) ? descriptions[event] : undefined;

  if (!description) return null;

  return (
    <Typography
      component="div"
      variant="caption"
      color="text.secondary"
      sx={{ mt: 0.25, lineHeight: 1.4, fontFamily: 'inherit', fontWeight: 400, maxWidth: 300, whiteSpace: 'normal' }}
    >
      {description}
    </Typography>
  );
}
