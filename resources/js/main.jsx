import React, { createContext, useContext } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import createApp from '@shopify/app-bridge';
import { CssBaseline, ThemeProvider, createTheme } from '@mui/material';
import Home from './pages/Home';
import GoogleAds from './pages/settings/GoogleAds';
import ComingSoon from './pages/settings/ComingSoon';

const theme = createTheme({
    palette: {
        primary: { main: '#5c6ac4' },
        background: { default: '#f6f6f7' },
    },
    typography: { fontFamily: 'inherit' },
});

/**
 * Initialise App Bridge eagerly using the API key injected by Blade and the
 * `host` query param that Shopify appends to every embedded app load.
 * Returns null when running outside the Shopify iframe (no host param).
 */
function buildShopifyApp() {
    const apiKey = window.__SHOPIFY_API_KEY__;
    const params = new URLSearchParams(window.location.search);
    const host = params.get('host');

    if (!apiKey || !host) {
        return null;
    }

    try {
        return createApp({ apiKey, host, forceRedirect: true });
    } catch {
        return null;
    }
}

export const ShopifyAppContext = createContext(null);
export const useShopifyApp = () => useContext(ShopifyAppContext);

const shopifyApp = buildShopifyApp();

function App() {
    return (
        <ShopifyAppContext.Provider value={shopifyApp}>
            <ThemeProvider theme={theme}>
                <CssBaseline />
                <BrowserRouter>
                    <Routes>
                        <Route path="/" element={<Home />} />
                        <Route path="/settings/google-ads" element={<GoogleAds />} />
                        <Route path="/settings/meta" element={<ComingSoon />} />
                        <Route path="/settings/tiktok" element={<ComingSoon />} />
                        <Route path="/settings/ga4" element={<ComingSoon />} />
                    </Routes>
                </BrowserRouter>
            </ThemeProvider>
        </ShopifyAppContext.Provider>
    );
}

const container = document.getElementById('root');
const root = createRoot(container);
root.render(<App />);
