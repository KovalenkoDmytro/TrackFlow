// resources/js/router/index.tsx
import { createBrowserRouter } from 'react-router-dom';
import { HomePage } from '../features/home/HomePage';
import { AnalyticsPage } from '../features/analytics/AnalyticsPage';
import { GoogleAdsPage } from '../features/settings/google-ads/GoogleAdsPage';
import { Ga4Page } from '../features/settings/ga4/Ga4Page';
import { MetaPage } from '../features/settings/meta/MetaPage';
import { ComingSoonPage } from '../features/settings/ComingSoonPage';

export const router = createBrowserRouter([
  { path: '/', element: <HomePage /> },
  { path: '/analytics', element: <AnalyticsPage /> },
  { path: '/analytics/platform/:platform', element: <AnalyticsPage /> },
  { path: '/settings/google-ads', element: <GoogleAdsPage /> },
  { path: '/settings/ga4', element: <Ga4Page /> },
  { path: '/settings/meta', element: <MetaPage /> },
  { path: '/settings/tiktok', element: <ComingSoonPage /> },
]);
