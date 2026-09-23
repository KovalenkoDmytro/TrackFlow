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
    primary: { main: '#5557d9', dark: '#4143b5', light: '#eeeeff', contrastText: '#ffffff' },
    success: { main: '#17845b' },
    background: { default: '#f7f8fc', paper: '#ffffff' },
    text: { primary: '#1c2030', secondary: '#72788a' },
    divider: '#eaecf2',
  },
  shape: { borderRadius: 12 },
  typography: {
    fontFamily: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    h4: { fontWeight: 750, letterSpacing: '-.04em' },
    h5: { fontWeight: 750, letterSpacing: '-.035em' },
    h6: { fontWeight: 700, letterSpacing: '-.02em' },
    button: { textTransform: 'none', fontWeight: 650, letterSpacing: 0 },
  },
  components: {
    MuiCard: { styleOverrides: { root: { borderColor: '#eaecf2', borderRadius: 16, boxShadow: '0 2px 8px rgba(25, 32, 56, .025)' } } },
    MuiButton: { styleOverrides: { root: { borderRadius: 10, minHeight: 38 }, contained: { boxShadow: 'none' } } },
    MuiOutlinedInput: { styleOverrides: { root: { borderRadius: 10 } } },
  },
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
