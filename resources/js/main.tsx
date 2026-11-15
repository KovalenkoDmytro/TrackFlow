// resources/js/main.tsx
import { createRoot } from 'react-dom/client';
import { RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import { router } from './router/index';

const theme = createTheme({
  palette: {
    primary: { main: '#315e46', dark: '#214934', light: '#e5f1df', contrastText: '#ffffff' },
    success: { main: '#4e9363' },
    background: { default: '#f5f6f3', paper: '#ffffff' },
    text: { primary: '#20352b', secondary: '#7a897e' },
    divider: '#e8ece6',
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
    MuiCard: { styleOverrides: { root: { borderColor: '#e8ece6', borderRadius: 18, boxShadow: '0 2px 8px rgba(29, 61, 42, .025)' } } },
    MuiButton: { styleOverrides: { root: { borderRadius: 10, minHeight: 38 }, contained: { boxShadow: 'none' } } },
    MuiOutlinedInput: { styleOverrides: { root: { borderRadius: 10 } } },
  },
});

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000 },
  },
});

const container = document.getElementById('root');
if (!container) throw new Error('Root element not found');

createRoot(container).render(
  <QueryClientProvider client={queryClient}>
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <RouterProvider router={router} />
    </ThemeProvider>
  </QueryClientProvider>,
);
