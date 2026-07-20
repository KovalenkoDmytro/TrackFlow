// resources/js/router/index.tsx
import { createBrowserRouter } from 'react-router-dom';
import { HomePage } from '../features/home/HomePage';
import { AnalyticsPage } from '../features/analytics/AnalyticsPage';
import { Ga4ReportPage } from '../features/analytics/ga4/Ga4ReportPage';
import { GoogleAdsPage } from '../features/settings/google-ads/GoogleAdsPage';
import { Ga4Page } from '../features/settings/ga4/Ga4Page';
import { GoogleOperatorPage } from '../features/settings/google/GoogleOperatorPage';
import { ComingSoonPage } from '../features/settings/ComingSoonPage';

export const router = createBrowserRouter([
  { path: '/', element: <HomePage /> },
  { path: '/analytics', element: <AnalyticsPage /> },
  { path: '/analytics/platform/:platform', element: <AnalyticsPage /> },
  { path: '/analytics/ga4-report', element: <Ga4ReportPage /> },
  { path: '/settings/google-ads', element: <GoogleAdsPage /> },
  { path: '/settings/ga4', element: <Ga4Page /> },
  { path: '/settings/google-account', element: <GoogleOperatorPage /> },
  { path: '/settings/meta', element: <ComingSoonPage /> },
  { path: '/settings/tiktok', element: <ComingSoonPage /> },
]);
