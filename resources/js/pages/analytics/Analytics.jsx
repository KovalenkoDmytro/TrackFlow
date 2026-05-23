import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useShopifyApp } from '../../main';
import {
    Alert,
    Box,
    Button,
    ButtonGroup,
    Card,
    CircularProgress,
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableRow,
    TextField,
    Typography,
} from '@mui/material';
import { createApiFetch } from '../../api';

// ---------------------------------------------------------------------------
// Utility
// ---------------------------------------------------------------------------

function todayString() {
    return new Date().toISOString().slice(0, 10);
}

function formatDateLabel(iso) {
    const [year, month, day] = iso.split('-').map(Number);
    const d = new Date(year, month - 1, day);
    return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function shiftDate(iso, days) {
    const [year, month, day] = iso.split('-').map(Number);
    const d = new Date(year, month - 1, day + days);
    return d.toISOString().slice(0, 10);
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function ModeToggle({ mode, onChange }) {
    return (
        <ButtonGroup size="small" variant="outlined">
            <Button
                variant={mode === 'single_day' ? 'contained' : 'outlined'}
                onClick={() => onChange('single_day')}
            >
                Single Day
            </Button>
            <Button
                variant={mode === 'range' ? 'contained' : 'outlined'}
                onClick={() => onChange('range')}
            >
                Date Range
            </Button>
        </ButtonGroup>
    );
}

function DayNavigator({ date, onChange }) {
    const isToday = date === todayString();

    return (
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Button size="small" variant="outlined" onClick={() => onChange(shiftDate(date, -1))}>
                ← Prev
            </Button>

            <Typography variant="body2" sx={{ minWidth: 160, textAlign: 'center' }}>
                {formatDateLabel(date)}
            </Typography>

            <Button
                size="small"
                variant="outlined"
                onClick={() => onChange(shiftDate(date, 1))}
                disabled={date >= todayString()}
            >
                Next →
            </Button>

            <Button
                size="small"
                variant={isToday ? 'contained' : 'outlined'}
                onClick={() => onChange(todayString())}
                disabled={isToday}
            >
                Today
            </Button>
        </Box>
    );
}

function DateRangePicker({ startDate, endDate, onApply }) {
    const [localStart, setLocalStart] = useState(startDate);
    const [localEnd, setLocalEnd] = useState(endDate);
    const [error, setError] = useState('');

    function handleApply() {
        if (!localStart || !localEnd) {
            setError('Please select both a start and end date.');
            return;
        }
        if (localStart > localEnd) {
            setError('Start date must be on or before end date.');
            return;
        }
        const days =
            Math.round((new Date(localEnd).getTime() - new Date(localStart).getTime()) / 86400000);
        if (days > 366) {
            setError('Date range may not exceed 366 days.');
            return;
        }
        setError('');
        onApply(localStart, localEnd);
    }

    return (
        <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2, flexWrap: 'wrap' }}>
            <TextField
                label="Start date"
                type="date"
                size="small"
                value={localStart}
                onChange={(e) => {
                    setLocalStart(e.target.value);
                    setError('');
                }}
                InputLabelProps={{ shrink: true }}
                inputProps={{ max: todayString() }}
            />
            <TextField
                label="End date"
                type="date"
                size="small"
                value={localEnd}
                onChange={(e) => {
                    setLocalEnd(e.target.value);
                    setError('');
                }}
                InputLabelProps={{ shrink: true }}
                inputProps={{ min: localStart, max: todayString() }}
            />
            <Button variant="contained" size="small" onClick={handleApply} sx={{ mt: 0.5 }}>
                Apply
            </Button>
            {error && (
                <Typography variant="caption" color="error" sx={{ alignSelf: 'center' }}>
                    {error}
                </Typography>
            )}
        </Box>
    );
}

function EventCountsTable({ counts, total, loading }) {
    if (loading) {
        return (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
                <CircularProgress size={28} />
            </Box>
        );
    }

    return (
        <Table size="small">
            <TableHead>
                <TableRow>
                    <TableCell>
                        <Typography variant="body2" fontWeight={600}>
                            Event
                        </Typography>
                    </TableCell>
                    <TableCell align="right">
                        <Typography variant="body2" fontWeight={600}>
                            Count
                        </Typography>
                    </TableCell>
                </TableRow>
            </TableHead>

            <TableBody>
                {counts.map((row) => (
                    <TableRow key={row.event}>
                        <TableCell>
                            <Typography
                                variant="body2"
                                color={row.count === 0 ? 'text.disabled' : 'text.primary'}
                            >
                                {row.label}
                            </Typography>
                        </TableCell>
                        <TableCell align="right">
                            <Typography
                                variant="body2"
                                sx={{ fontVariantNumeric: 'tabular-nums' }}
                                color={row.count === 0 ? 'text.disabled' : 'text.primary'}
                            >
                                {row.count.toLocaleString()}
                            </Typography>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>

            <TableFooter>
                <TableRow>
                    <TableCell>
                        <Typography variant="body2" fontWeight={700}>
                            Total
                        </Typography>
                    </TableCell>
                    <TableCell align="right">
                        <Typography
                            variant="body2"
                            fontWeight={700}
                            sx={{ fontVariantNumeric: 'tabular-nums' }}
                        >
                            {total.toLocaleString()}
                        </Typography>
                    </TableCell>
                </TableRow>
            </TableFooter>
        </Table>
    );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export default function Analytics() {
    const app = useShopifyApp();
    const navigate = useNavigate();
    const apiFetch = useMemo(() => createApiFetch(app), [app]);

    const today = todayString();

    const [mode, setMode] = useState('single_day');
    const [date, setDate] = useState(today);
    const [startDate, setStartDate] = useState(today);
    const [endDate, setEndDate] = useState(today);

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    // Track the latest request to discard stale responses
    const requestIdRef = useRef(0);

    const fetchAnalytics = useCallback(
        (filters) => {
            const id = ++requestIdRef.current;
            setLoading(true);
            setError(null);

            const params = new URLSearchParams();
            params.set('mode', filters.mode);
            if (filters.mode === 'single_day') {
                params.set('date', filters.date);
            } else {
                params.set('start_date', filters.start_date);
                params.set('end_date', filters.end_date);
            }

            apiFetch(`/api/analytics?${params.toString()}`)
                .then(async (res) => {
                    if (!res.ok) {
                        const body = await res.json().catch(() => ({}));
                        throw new Error(body.message ?? 'Failed to load analytics');
                    }
                    return res.json();
                })
                .then((json) => {
                    if (id !== requestIdRef.current) return; // stale
                    setData(json);
                })
                .catch((err) => {
                    if (id !== requestIdRef.current) return;
                    setError(err.message);
                })
                .finally(() => {
                    if (id === requestIdRef.current) setLoading(false);
                });
        },
        [apiFetch],
    );

    // Fire on mount and whenever filters change
    useEffect(() => {
        const filters =
            mode === 'single_day'
                ? { mode, date }
                : { mode, start_date: startDate, end_date: endDate };
        fetchAnalytics(filters);
    }, [mode, date, startDate, endDate, fetchAnalytics]);

    function handleModeChange(newMode) {
        setMode(newMode);
    }

    function handleDayChange(newDate) {
        setDate(newDate);
    }

    function handleRangeApply(s, e) {
        setStartDate(s);
        setEndDate(e);
    }

    const counts  = data?.summary?.counts ?? [];
    const total   = data?.summary?.total ?? 0;
    const period  = data?.summary?.period ?? null;

    return (
        <Box sx={{ maxWidth: 800, mx: 'auto', p: 3 }}>
            {/* Header */}
            <Button variant="text" size="small" sx={{ mb: 2 }} onClick={() => navigate('/')}>
                ← Back
            </Button>

            <Typography variant="h6" fontWeight={700} sx={{ mb: 3 }}>
                Analytics
            </Typography>

            {error && (
                <Alert severity="error" sx={{ mb: 3 }} onClose={() => setError(null)}>
                    {error}
                </Alert>
            )}

            {/* Filter controls */}
            <Card variant="outlined" sx={{ mb: 3, p: 2 }}>
                <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                    <ModeToggle mode={mode} onChange={handleModeChange} />

                    {mode === 'single_day' ? (
                        <DayNavigator date={date} onChange={handleDayChange} />
                    ) : (
                        <DateRangePicker
                            startDate={startDate}
                            endDate={endDate}
                            onApply={handleRangeApply}
                        />
                    )}

                    {period && !loading && (
                        <Typography variant="caption" color="text.secondary">
                            Showing {period.label}
                            {period.days > 1 ? ` (${period.days} days)` : ''}
                        </Typography>
                    )}
                </Box>
            </Card>

            {/* Counts table */}
            <Card variant="outlined">
                <EventCountsTable
                    counts={counts}
                    total={total}
                    loading={loading}
                />
            </Card>
        </Box>
    );
}
