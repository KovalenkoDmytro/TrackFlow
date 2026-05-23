// resources/js/main.tsx
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import createApp from '@shopify/app-bridge';
import { ShopifyAppContext } from './hooks/useShopify';
import { router } from './router/index';

const theme = createTheme({
  palette: {
    primary: { main: '#5c6ac4' },
    background: { default: '#f6f6f7' },
  },
  typography: { fontFamily: 'inherit' },
});

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000 },
  },
});

function buildShopifyApp() {
  if (import.meta.env.DEV) return null;

  const apiKey = window.__SHOPIFY_API_KEY__;
  const params = new URLSearchParams(window.location.search);
  const host = params.get('host');

  if (!apiKey || !host) return null;

  try {
    return createApp({ apiKey, host, forceRedirect: true });
  } catch {
    return null;
  }
}

const shopifyApp = buildShopifyApp();

const container = document.getElementById('root');
if (!container) throw new Error('Root element not found');

createRoot(container).render(
  <ShopifyAppContext.Provider value={shopifyApp}>
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        <RouterProvider router={router} />
      </ThemeProvider>
    </QueryClientProvider>
  </ShopifyAppContext.Provider>,
);
