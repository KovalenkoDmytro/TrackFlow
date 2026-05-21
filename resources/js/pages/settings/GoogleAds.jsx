import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useShopifyApp } from '../../main';
import {
    Alert,
    Box,
    Button,
    Card,
    CardContent,
    Chip,
    CircularProgress,
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableRow,
    TextField,
    Typography,
} from '@mui/material';
import { createApiFetch } from '../../api';

const INITIAL_FORM = {
    customer_id: '',
    mcc_id: '',
    developer_token: '',
    oauth_client_id: '',
    oauth_client_secret: '',
    oauth_refresh_token: '',
};

export default function GoogleAds() {
    const app = useShopifyApp();
    const navigate = useNavigate();
    const apiFetch = createApiFetch(app);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);

    const [integration, setIntegration] = useState(null);
    const [mappings, setMappings] = useState([]);
    const [form, setForm] = useState(INITIAL_FORM);
    const [errors, setErrors] = useState({});
    const [successMessage, setSuccessMessage] = useState('');
    const [apiError, setApiError] = useState('');

    useEffect(() => {
        apiFetch('/api/settings/google-ads')
            .then(async (res) => {
                if (!res.ok) throw new Error('Failed to load settings');
                return res.json();
            })
            .then((data) => {
                setIntegration(data.integration);
                setMappings(data.mappings ?? []);
                if (data.credentials) {
                    setForm({
                        customer_id: data.credentials.customer_id ?? '',
                        mcc_id: data.credentials.mcc_id ?? '',
                        developer_token: data.credentials.developer_token ?? '',
                        oauth_client_id: data.credentials.oauth?.client_id ?? '',
                        oauth_client_secret: data.credentials.oauth?.client_secret ?? '',
                        oauth_refresh_token: data.credentials.oauth?.refresh_token ?? '',
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
            const res = await apiFetch('/api/settings/google-ads', {
                method: 'POST',
                body: JSON.stringify(form),
            });

            const data = await res.json();

            if (res.status === 422 && data.errors) {
                setErrors(data.errors);
                return;
            }

            if (!res.ok) {
                setApiError(data.error ?? 'An unexpected error occurred.');
                return;
            }

            setSuccessMessage('Google Ads connected. Conversion actions are being created in the background.');
            setIntegration(data.integration ?? integration);
        } catch {
            setApiError('Network error. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    async function handleDisconnect() {
        if (!window.confirm('Disconnect Google Ads? This will stop all conversion tracking.')) {
            return;
        }

        setDisconnecting(true);
        setApiError('');
        setSuccessMessage('');

        try {
            const res = await apiFetch('/api/settings/google-ads', { method: 'DELETE' });
            if (!res.ok) {
                const data = await res.json();
                setApiError(data.error ?? 'Failed to disconnect.');
                return;
            }
            setSuccessMessage('Google Ads disconnected.');
            setIntegration((prev) => (prev ? { ...prev, active: false } : null));
            setMappings([]);
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

    const isConnected = Boolean(integration?.active);

    return (
        <Box sx={{ maxWidth: 720, mx: 'auto', p: 3 }}>
            <Button variant="text" size="small" sx={{ mb: 2 }} onClick={() => navigate('/')}>
                ← Back
            </Button>

            <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 3 }}>
                <Typography variant="h6" fontWeight={700}>
                    Google Ads Integration
                </Typography>
                {isConnected && (
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

            {errors.credentials && (
                <Alert severity="error" sx={{ mb: 3 }}>
                    {errors.credentials}
                </Alert>
            )}

            <Card variant="outlined">
                <CardContent>
                    <Box component="form" onSubmit={handleSubmit} sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}>
                        <TextField
                            label="Customer ID"
                            name="customer_id"
                            value={form.customer_id}
                            onChange={handleChange}
                            placeholder="123-456-7890"
                            helperText={errors.customer_id ?? 'Your Google Ads account ID (not MCC)'}
                            error={Boolean(errors.customer_id)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="MCC Customer ID (optional)"
                            name="mcc_id"
                            value={form.mcc_id}
                            onChange={handleChange}
                            placeholder="123-456-7890"
                            helperText={errors.mcc_id ?? "Leave blank if you don't use a manager account"}
                            error={Boolean(errors.mcc_id)}
                            fullWidth
                        />

                        <TextField
                            label="Developer Token"
                            name="developer_token"
                            value={form.developer_token}
                            onChange={handleChange}
                            helperText={errors.developer_token}
                            error={Boolean(errors.developer_token)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="OAuth Client ID"
                            name="oauth_client_id"
                            value={form.oauth_client_id}
                            onChange={handleChange}
                            helperText={errors.oauth_client_id}
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
                            helperText={errors.oauth_client_secret}
                            error={Boolean(errors.oauth_client_secret)}
                            fullWidth
                            required
                        />

                        <TextField
                            label="OAuth Refresh Token"
                            name="oauth_refresh_token"
                            value={form.oauth_refresh_token}
                            onChange={handleChange}
                            helperText={errors.oauth_refresh_token}
                            error={Boolean(errors.oauth_refresh_token)}
                            fullWidth
                            required
                            multiline
                            rows={3}
                            inputProps={{ style: { fontFamily: 'monospace' } }}
                        />

                        <Box>
                            <Button
                                type="submit"
                                variant="contained"
                                disabled={saving}
                            >
                                {saving ? 'Saving…' : 'Save & Connect'}
                            </Button>
                        </Box>
                    </Box>
                </CardContent>
            </Card>

            {mappings.length > 0 && (
                <Box sx={{ mt: 4 }}>
                    <Typography variant="subtitle1" fontWeight={600} sx={{ mb: 1 }}>
                        Conversion Actions
                    </Typography>
                    <Card variant="outlined">
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Event</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Google Ads Action ID</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {mappings.map((mapping) => (
                                    <TableRow key={mapping.id}>
                                        <TableCell sx={{ fontFamily: 'monospace' }}>{mapping.event}</TableCell>
                                        <TableCell>
                                            <Chip
                                                label={mapping.active ? 'Active' : 'Inactive'}
                                                color={mapping.active ? 'success' : 'default'}
                                                size="small"
                                            />
                                        </TableCell>
                                        <TableCell sx={{ fontFamily: 'monospace', fontSize: 12 }}>
                                            {mapping.external_action_id ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </Box>
            )}
        </Box>
    );
}
