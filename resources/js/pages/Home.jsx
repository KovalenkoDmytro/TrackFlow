import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useShopifyApp } from '../main';
import {
    Box,
    Card,
    CardContent,
    Chip,
    CircularProgress,
    Grid,
    Typography,
    Button,
    Alert,
} from '@mui/material';
import { createApiFetch } from '../api';

const PLATFORMS = [
    { key: 'google_ads', label: 'Google Ads',  initial: 'G', color: '#4285F4', bg: '#e8f0fe', route: '/settings/google-ads' },
    { key: 'meta',       label: 'Meta',         initial: 'M', color: '#1877F2', bg: '#e7f3ff', route: '/settings/meta' },
    { key: 'tiktok',     label: 'TikTok',       initial: 'T', color: '#ffffff', bg: '#010101', route: '/settings/tiktok' },
    { key: 'ga4',        label: 'GA4',           initial: 'A', color: '#E37400', bg: '#fff3e0', route: '/settings/ga4' },
];

export default function Home() {
    const app = useShopifyApp();
    const navigate = useNavigate();
    const apiFetch = createApiFetch(app);

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [shop, setShop] = useState(null);
    const [integrations, setIntegrations] = useState({});

    useEffect(() => {
        apiFetch('/api/shop-status')
            .then(async (res) => {
                if (!res.ok) throw new Error('Failed to load shop status');
                return res.json();
            })
            .then((data) => {
                setShop(data.shop);
                setIntegrations(data.integrations);
            })
            .catch((err) => setError(err.message))
            .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (loading) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', mt: 8 }}>
                <CircularProgress />
            </Box>
        );
    }

    if (error) {
        return (
            <Box sx={{ p: 3 }}>
                <Alert severity="error">{error}</Alert>
            </Box>
        );
    }

    return (
        <Box sx={{ maxWidth: 900, mx: 'auto', p: 3 }}>
            <Typography variant="h5" fontWeight={700} gutterBottom>
                TrackFlow
            </Typography>

            {/* Pixel status */}
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>
                Pixel Status
            </Typography>
            <Card variant="outlined" sx={{ mb: 4 }}>
                <CardContent sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                    {shop?.shopify_pixel_id ? (
                        <>
                            <Chip label="Active" color="success" size="small" />
                            <Typography variant="body2" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
                                {shop.shopify_pixel_id}
                            </Typography>
                        </>
                    ) : (
                        <Chip label="Inactive — pixel will be created on next authentication" size="small" />
                    )}
                </CardContent>
            </Card>

            {/* Platform integrations */}
            <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>
                Platform Integrations
            </Typography>
            <Grid container spacing={2}>
                {PLATFORMS.map((platform) => {
                    const connected = Boolean(integrations[platform.key]);
                    return (
                        <Grid item xs={12} sm={6} key={platform.key}>
                            <Card variant="outlined">
                                <CardContent sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                                        <Box
                                            sx={{
                                                width: 36,
                                                height: 36,
                                                borderRadius: '50%',
                                                bgcolor: platform.bg,
                                                display: 'flex',
                                                alignItems: 'center',
                                                justifyContent: 'center',
                                                fontWeight: 700,
                                                fontSize: 14,
                                                color: platform.color,
                                            }}
                                        >
                                            {platform.initial}
                                        </Box>
                                        <Box>
                                            <Typography variant="body2" fontWeight={500}>
                                                {platform.label}
                                            </Typography>
                                            <Chip
                                                label={connected ? 'Connected' : 'Not Connected'}
                                                color={connected ? 'success' : 'default'}
                                                size="small"
                                                sx={{ mt: 0.5 }}
                                            />
                                        </Box>
                                    </Box>
                                    <Button
                                        variant="outlined"
                                        size="small"
                                        onClick={() => navigate(platform.route)}
                                    >
                                        {connected ? 'Manage' : 'Connect'}
                                    </Button>
                                </CardContent>
                            </Card>
                        </Grid>
                    );
                })}
            </Grid>
        </Box>
    );
}
