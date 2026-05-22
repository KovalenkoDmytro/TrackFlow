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
    Link,
    Paper,
    TextField,
    Typography,
} from '@mui/material';
import { createApiFetch } from '../../api';

function isScopeError(message) {
    if (!message) return false;
    const lower = message.toLowerCase();
    return lower.includes('insufficient') || lower.includes('scopes');
}

function ScopeErrorPanel() {
    return (
        <Paper variant="outlined" sx={{ mt: 2, p: 2, borderColor: 'warning.light' }}>
            <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 1 }}>
                Wrong OAuth scope
            </Typography>
            <Typography variant="body2" sx={{ mb: 1 }}>
                The OAuth Refresh Token must have the <code>analytics.edit</code> scope. Generate a new token at{' '}
                <Link href="https://developers.google.com/oauthplayground/" target="_blank" rel="noopener">
                    Google OAuth Playground
                </Link>
                :
            </Typography>
            <Box component="ol" sx={{ pl: 2.5, m: 0 }}>
                <Box component="li">
                    <Typography variant="body2">
                        Click ⚙️ → enable "Use your own OAuth credentials" → enter Client ID and Secret
                    </Typography>
                </Box>
                <Box component="li">
                    <Typography variant="body2">
                        Find <strong>Google Analytics Admin API v1</strong> → select <code>analytics.edit</code>
                    </Typography>
                </Box>
                <Box component="li">
                    <Typography variant="body2">
                        Click <strong>Authorize APIs</strong> → <strong>Exchange authorization code for tokens</strong>
                    </Typography>
                </Box>
                <Box component="li">
                    <Typography variant="body2">
                        Copy the new <code>refresh_token</code> and paste it above
                    </Typography>
                </Box>
            </Box>
        </Paper>
    );
}

const INITIAL_FORM = {
    measurement_id: '',
    api_secret: '',
    property_id: '',
    oauth_client_id: '',
    oauth_client_secret: '',
    oauth_refresh_token: '',
};

const DRAFT_KEY = 'trackflow_ga4_draft';
const DRAFT_FIELDS = ['property_id', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'];

function loadDraft() {
    try {
        return JSON.parse(localStorage.getItem(DRAFT_KEY) || '{}');
    } catch {
        return {};
    }
}

function saveDraft(form) {
    const draft = {};
    DRAFT_FIELDS.forEach((k) => {
        draft[k] = form[k];
    });
    localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
}

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
                const draft = loadDraft();
                setConnected(data.connected ?? false);
                setForm({
                    measurement_id: data.credentials?.measurement_id ?? '',
                    api_secret: data.credentials?.api_secret ?? '',
                    property_id: data.credentials?.property_id || draft.property_id || '',
                    oauth_client_id: data.credentials?.oauth_client_id || draft.oauth_client_id || '',
                    oauth_client_secret: data.credentials?.oauth_client_secret || draft.oauth_client_secret || '',
                    oauth_refresh_token: data.credentials?.oauth_refresh_token || draft.oauth_refresh_token || '',
                });
            })
            .catch((err) => setApiError(err.message))
            .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    function handleChange(e) {
        const { name, value } = e.target;
        setForm((prev) => {
            const newForm = { ...prev, [name]: value };
            if (DRAFT_FIELDS.includes(name)) {
                saveDraft(newForm);
            }
            return newForm;
        });
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
            localStorage.removeItem(DRAFT_KEY);
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
                <Box sx={{ mb: 3 }}>
                    <Alert severity="error" onClose={() => setApiError('')}>
                        {apiError}
                    </Alert>
                    {isScopeError(apiError) && <ScopeErrorPanel />}
                </Box>
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

                        <Typography variant="body2" color="text.secondary">
                            Optional: provide these to automatically create Key Events (add_to_cart, begin_checkout, etc.) in your GA4 property.
                        </Typography>

                        <TextField
                            label="Property ID"
                            name="property_id"
                            value={form.property_id}
                            onChange={handleChange}
                            helperText={
                                errors.property_id ??
                                'Numeric GA4 Property ID (Admin → Property Settings). Required for automatic Key Events creation.'
                            }
                            error={Boolean(errors.property_id)}
                            fullWidth
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
                        />

                        <TextField
                            label="OAuth Refresh Token"
                            name="oauth_refresh_token"
                            value={form.oauth_refresh_token}
                            onChange={handleChange}
                            helperText={errors.oauth_refresh_token}
                            error={Boolean(errors.oauth_refresh_token)}
                            fullWidth
                            multiline
                            rows={3}
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
