import { Card, CardContent, Chip, Typography } from '@mui/material';

interface PixelStatusCardProps {
  pixelId: string | null | undefined;
}

export function PixelStatusCard({ pixelId }: PixelStatusCardProps) {
  return (
    <Card variant="outlined" sx={{ mb: 4 }}>
      <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
        {pixelId ? (
          <>
            <Chip label="Active" color="success" size="small" />
            <Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
              {pixelId}
            </Typography>
          </>
        ) : (
          <Chip label="Inactive — pixel will be created on next authentication" size="small" />
        )}
      </CardContent>
    </Card>
  );
}
