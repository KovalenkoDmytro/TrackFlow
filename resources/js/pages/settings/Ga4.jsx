import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useShopifyApp } from '../../main';
import {
    Alert,
    Box,
    Button,
    Card,
    CardContent,
    CircularProgress,
    TextField,
    Typography,
} from '@mui/material';
import { createApiFetch } from '../../api';

const INITIAL_FORM = {
    measurement_id: '',
    api_secret: '',
    property_id: '',
    oauth_client_id: '',
    oauth_client_secret: '',
    oauth_refresh_token: '',
};

export default function Ga4() {
    const app = useShopifyApp();
    const navigate = useNavigate();
    const apiFetch = createApiFetch(app);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);

    const [connected, setConnected] = useState(false);
    const [form, setForm] = useState(INITIAL_FORM);
    const [errors, setErrors] = useState({});
    const [successMessage, setSuccessMessage] = useState('');
    const [apiError, setApiError] = useState('');

    useEffect(() => {
        apiFetch('/api/settings/ga4')
            .then(async (res) => {
                if (!res.ok) throw new Error('Failed to load settings');
                return res.json();
            })
            .then((data) => {
                setConnected(data.connected ?? false);
                if (data.credentials) {
                    setForm({
                        measurement_id: data.credentials.measurement_id ?? '',
                        api_secret: data.credentials.api_secret ?? '',
                        property_id: data.credentials.property_id ?? '',
                        oauth_client_id: data.credentials.oauth_client_id ?? '',
                        oauth_client_secret: data.credentials.oauth_client_secret ?? '',
                        oauth_refresh_token: data.credentials.oauth_refresh_token ?? '',
                    });
                }
            })
            .catch((err) => setApiError(err.message))
            .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function handleChange(e) {
        const { name, value } = e.target;
        setForm((prev) => ({ ...prev, [name]: value }));
        setErrors((prev) => ({ ...prev, [name]: undefined }));
    }

    async function handleSubmit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        setApiError('');
        setSuccessMessage('');

        try {
            const res = await apiFetch('/api/settings/ga4', {
                method: 'POST',
                body: JSON.stringify(form),
            });

            const data = await res.json();

            if (res.status === 422 && data.errors) {
                setErrors(data.errors);
                return;
            }

            if (!res.ok) {
                setApiError(data.message ?? 'An unexpected error occurred.');
                return;
            }

            setSuccessMessage('GA4 connected. Event mappings are being configured in the background.');
            setConnected(true);
        } catch {
            setApiError('Network error. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    async function handleDisconnect() {
        if (!window.confirm('Disconnect GA4? This will stop all GA4 event tracking.')) {
            return;
        }

        setDisconnecting(true);
        setApiError('');
        setSuccessMessage('');

        try {
            const res = await apiFetch('/api/settings/ga4', { method: 'DELETE' });
            if (!res.ok) {
                const data = await res.json();
                setApiError(data.message ?? 'Failed to disconnect.');
                return;
            }
            setSuccessMessage('GA4 disconnected.');
            setConnected(false);
        } catch {
            setApiError('Network error. Please try again.');
        } finally {
            setDisconnecting(false);
        }
    }

    if (loading) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', mt: 8 }}>
                <CircularProgress />
            </Box>
        );
    }

    return (
        <Box sx={{ maxWidth: 720, mx: 'auto', p: 3 }}>
            <Button variant="text" size="small" sx={{ mb: 2 }} onClick={() => navigate('/')}>
                ← Back
            </Button>

            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 3 }}>
                <Typography variant="h6" fontWeight={700}>
                    Google Analytics 4 Integration
                </Typography>
                {connected && (
                    <Button
                        variant="outlined"
                        color="error"
                        size="small"
                        onClick={handleDisconnect}
                        disabled={disconnecting}
                    >
                        {disconnecting ? 'Disconnecting…' : 'Disconnect'}
                    </Button>
                )}
            </Box>

            {successMessage && (
                <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccessMessage('')}>
                    {successMessage}
                </Alert>
            )}

            {apiError && (
                <Alert severity="error" sx={{ mb: 3 }} onClose={() => setApiError('')}>
                    {apiError}
                </Alert>
            )}

            <Card variant="outlined">
                <CardContent>
                    <Box component="form" onSubmit={handleSubmit} sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}>
                        <TextField
                            label="Measurement ID"
                            name="measurement_id"
                            value={form.measurement_id}
                            onChange={handleChange}
                            placeholder="G-XXXXXXXXXX"
                            helperText={errors.measurement_id ?? 'Your GA4 property Measurement ID (e.g. G-XXXXXXXXXX)'}
                            error={Boolean(errors.measurement_id)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="API Secret"
                            name="api_secret"
                            value={form.api_secret}
                            onChange={handleChange}
                            helperText={
                                errors.api_secret ??
                                'Create one in GA4: Admin → Data Streams → your stream → Measurement Protocol API secrets'
                            }
                            error={Boolean(errors.api_secret)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="Property ID"
                            name="property_id"
                            value={form.property_id}
                            onChange={handleChange}
                            placeholder="123456789"
                            helperText={
                                errors.property_id ??
                                'Numeric GA4 Property ID from Admin → Property Settings (e.g. 123456789)'
                            }
                            error={Boolean(errors.property_id)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="OAuth Client ID"
                            name="oauth_client_id"
                            value={form.oauth_client_id}
                            onChange={handleChange}
                            helperText={
                                errors.oauth_client_id ??
                                'From Google Cloud Console → APIs & Services → Credentials'
                            }
                            error={Boolean(errors.oauth_client_id)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="OAuth Client Secret"
                            name="oauth_client_secret"
                            type="password"
                            value={form.oauth_client_secret}
                            onChange={handleChange}
                            helperText={
                                errors.oauth_client_secret ??
                                'From Google Cloud Console → APIs & Services → Credentials'
                            }
                            error={Boolean(errors.oauth_client_secret)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="OAuth Refresh Token"
                            name="oauth_refresh_token"
                            value={form.oauth_refresh_token}
                            onChange={handleChange}
                            helperText={
                                errors.oauth_refresh_token ??
                                'Must include analytics.edit scope'
                            }
                            error={Boolean(errors.oauth_refresh_token)}
                            fullWidth
                            required
                            multiline
                            rows={3}
                            inputProps={{ style: { fontFamily: 'monospace' } }}
                        />

                        <Box>
                            <Button type="submit" variant="contained" disabled={saving}>
                                {saving ? 'Saving…' : 'Save & Connect'}
                            </Button>
                        </Box>
                    </Box>
                </CardContent>
            </Card>
        </Box>
    );
}
