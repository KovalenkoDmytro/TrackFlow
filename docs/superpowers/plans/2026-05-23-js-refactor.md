# JS Frontend Refactoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor `resources/js/` from a flat untyped React SPA into a TypeScript feature-based codebase with TanStack Query, React Hook Form, and Zod.

**Architecture:** Feature-based structure under `features/`, shared UI in `components/ui/`, typed API layer in `api/`. New files are created alongside old ones until the entry point switches in Task 12 — then old files are deleted.

**Tech Stack:** React 19, React Router v7, MUI v9, Shopify App Bridge v3, TypeScript 5, TanStack Query v5, React Hook Form v7, Zod v3

---

### Task 1: Install packages and configure TypeScript

**Files:**
- Create: `tsconfig.json`
- Modify: `vite.config.js` (entry point only — stays as .js for now)

- [ ] **Step 1: Install new packages**

```bash
docker compose exec app yarn add @tanstack/react-query react-hook-form zod @hookform/resolvers
docker compose exec app yarn add -D typescript @types/react @types/react-dom
```

Expected: packages added to `package.json` and `yarn.lock`.

- [ ] **Step 2: Create tsconfig.json at project root**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "useDefineForClassFields": true,
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "isolatedModules": true,
    "moduleDetection": "force",
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "noFallthroughCasesInSwitch": true
  },
  "include": ["resources/js"]
}
```

- [ ] **Step 3: Create resources/js/vite-env.d.ts**

This makes `import.meta.env` typed throughout the project.

```typescript
/// <reference types="vite/client" />
```

- [ ] **Step 4: Verify TypeScript is callable**

```bash
docker compose exec app yarn tsc --version
```

Expected: `Version 5.x.x`

- [ ] **Step 5: Commit**

```bash
git add tsconfig.json resources/js/vite-env.d.ts package.json yarn.lock
git commit -m "chore: add TypeScript and TanStack Query / RHF / Zod packages"
```

---

### Task 2: Create types/

**Files:**
- Create: `resources/js/types/api.ts`
- Create: `resources/js/types/shopify.ts`

- [ ] **Step 1: Create resources/js/types/api.ts**

```typescript
// resources/js/types/api.ts

export interface ShopRecord {
  shopify_pixel_id: string | null;
}

export interface ShopStatusResponse {
  shop: ShopRecord;
  integrations: Record<string, boolean>;
}

export type AnalyticsMode = 'single_day' | 'range';

export interface AnalyticsParams {
  mode: AnalyticsMode;
  date?: string;
  start_date?: string;
  end_date?: string;
}

export interface EventCount {
  event: string;
  label: string;
  count: number;
}

export interface AnalyticsPeriod {
  label: string;
  days: number;
}

export interface AnalyticsSummary {
  counts: EventCount[];
  total: number;
  period: AnalyticsPeriod | null;
}

export interface AnalyticsResponse {
  summary: AnalyticsSummary;
}

export interface GoogleAdsOAuth {
  client_id: string;
  client_secret: string;
  refresh_token: string;
}

export interface GoogleAdsCredentials {
  customer_id: string;
  mcc_id: string;
  developer_token: string;
  oauth: GoogleAdsOAuth;
}

export interface GoogleAdsIntegration {
  active: boolean;
}

export interface GoogleAdsMapping {
  id: number;
  event: string;
  active: boolean;
  external_action_id: string | null;
}

export interface GoogleAdsSettingsResponse {
  integration: GoogleAdsIntegration | null;
  mappings: GoogleAdsMapping[];
  credentials: GoogleAdsCredentials | null;
}

export interface GoogleAdsSaveResponse {
  integration: GoogleAdsIntegration | null;
}

export interface GoogleAdsFormData {
  customer_id: string;
  mcc_id: string;
  developer_token: string;
  oauth_client_id: string;
  oauth_client_secret: string;
  oauth_refresh_token: string;
}

export interface Ga4Credentials {
  measurement_id: string;
  api_secret: string;
  property_id: string;
  oauth_client_id: string;
  oauth_client_secret: string;
  oauth_refresh_token: string;
}

export interface Ga4SettingsResponse {
  connected: boolean;
  credentials: Ga4Credentials | null;
}

export interface Ga4FormData {
  measurement_id: string;
  api_secret: string;
  property_id: string;
  oauth_client_id: string;
  oauth_client_secret: string;
  oauth_refresh_token: string;
}

export class ValidationError extends Error {
  constructor(public readonly fieldErrors: Record<string, string[]>) {
    super('Validation failed');
    this.name = 'ValidationError';
  }
}
```

- [ ] **Step 2: Create resources/js/types/shopify.ts**

```typescript
// resources/js/types/shopify.ts
import type { ClientApplication } from '@shopify/app-bridge';

export type { ClientApplication };

declare global {
  interface Window {
    __SHOPIFY_API_KEY__?: string;
  }
}
```

- [ ] **Step 3: Verify types compile**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors (only two small type files, nothing to validate yet).

- [ ] **Step 4: Commit**

```bash
git add resources/js/types/
git commit -m "feat: add TypeScript API and Shopify types"
```

---

### Task 3: Create api/client.ts

**Files:**
- Create: `resources/js/api/client.ts`

- [ ] **Step 1: Create resources/js/api/client.ts**

```typescript
// resources/js/api/client.ts
import { getSessionToken } from '@shopify/app-bridge/utilities';
import type { ClientApplication } from '../types/shopify';

const BASE_URL = import.meta.env.DEV
  ? (import.meta.env.VITE_APP_URL ?? 'http://localhost:8000')
  : '';

export type ApiClient = (url: string, options?: RequestInit) => Promise<Response>;

export function createApiClient(app: ClientApplication | null): ApiClient {
  return async function apiClient(url: string, options: RequestInit = {}): Promise<Response> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers as Record<string, string> | undefined),
    };

    if (app) {
      try {
        const token = await getSessionToken(app);
        headers['Authorization'] = `Bearer ${token}`;
      } catch {
        // App Bridge not fully initialised — fall back to cookie auth.
      }
    }

    const resolvedUrl = url.startsWith('/') ? `${BASE_URL}${url}` : url;
    return fetch(resolvedUrl, { ...options, headers, credentials: 'include' });
  };
}
```

- [ ] **Step 2: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/api/client.ts
git commit -m "feat: add typed API client (migrated from api.js)"
```

---

### Task 4: Create api domain modules

**Files:**
- Create: `resources/js/api/shop.ts`
- Create: `resources/js/api/analytics.ts`
- Create: `resources/js/api/settings.ts`

- [ ] **Step 1: Create resources/js/api/shop.ts**

```typescript
// resources/js/api/shop.ts
import type { ApiClient } from './client';
import type { ShopStatusResponse } from '../types/api';

export async function getShopStatus(client: ApiClient): Promise<ShopStatusResponse> {
  const res = await client('/api/shop-status');
  if (!res.ok) throw new Error('Failed to load shop status');
  return res.json() as Promise<ShopStatusResponse>;
}
```

- [ ] **Step 2: Create resources/js/api/analytics.ts**

```typescript
// resources/js/api/analytics.ts
import type { ApiClient } from './client';
import type { AnalyticsParams, AnalyticsResponse } from '../types/api';

export async function getAnalytics(
  client: ApiClient,
  params: AnalyticsParams,
): Promise<AnalyticsResponse> {
  const search = new URLSearchParams();
  search.set('mode', params.mode);
  if (params.mode === 'single_day') {
    search.set('date', params.date ?? '');
  } else {
    search.set('start_date', params.start_date ?? '');
    search.set('end_date', params.end_date ?? '');
  }

  const res = await client(`/api/analytics?${search.toString()}`);
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { message?: string };
    throw new Error(body.message ?? 'Failed to load analytics');
  }
  return res.json() as Promise<AnalyticsResponse>;
}
```

- [ ] **Step 3: Create resources/js/api/settings.ts**

```typescript
// resources/js/api/settings.ts
import type { ApiClient } from './client';
import type {
  GoogleAdsSettingsResponse,
  GoogleAdsFormData,
  GoogleAdsSaveResponse,
  Ga4SettingsResponse,
  Ga4FormData,
} from '../types/api';
import { ValidationError } from '../types/api';

export async function getGoogleAdsSettings(
  client: ApiClient,
): Promise<GoogleAdsSettingsResponse> {
  const res = await client('/api/settings/google-ads');
  if (!res.ok) throw new Error('Failed to load settings');
  return res.json() as Promise<GoogleAdsSettingsResponse>;
}

export async function saveGoogleAdsSettings(
  client: ApiClient,
  data: GoogleAdsFormData,
): Promise<GoogleAdsSaveResponse> {
  const res = await client('/api/settings/google-ads', {
    method: 'POST',
    body: JSON.stringify(data),
  });
  const body = await res.json() as Record<string, unknown>;
  if (res.status === 422 && body['errors']) {
    throw new ValidationError(body['errors'] as Record<string, string[]>);
  }
  if (!res.ok) {
    throw new Error((body['error'] as string | undefined) ?? 'An unexpected error occurred.');
  }
  return body as GoogleAdsSaveResponse;
}

export async function deleteGoogleAdsSettings(client: ApiClient): Promise<void> {
  const res = await client('/api/settings/google-ads', { method: 'DELETE' });
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { error?: string };
    throw new Error(body.error ?? 'Failed to disconnect.');
  }
}

export async function getGa4Settings(client: ApiClient): Promise<Ga4SettingsResponse> {
  const res = await client('/api/settings/ga4');
  if (!res.ok) throw new Error('Failed to load settings');
  return res.json() as Promise<Ga4SettingsResponse>;
}

export async function saveGa4Settings(
  client: ApiClient,
  data: Ga4FormData,
): Promise<void> {
  const res = await client('/api/settings/ga4', {
    method: 'POST',
    body: JSON.stringify(data),
  });
  const body = await res.json() as Record<string, unknown>;
  if (res.status === 422 && body['errors']) {
    throw new ValidationError(body['errors'] as Record<string, string[]>);
  }
  if (!res.ok) {
    throw new Error((body['message'] as string | undefined) ?? 'An unexpected error occurred.');
  }
}

export async function deleteGa4Settings(client: ApiClient): Promise<void> {
  const res = await client('/api/settings/ga4', { method: 'DELETE' });
  if (!res.ok) {
    const body = await res.json().catch(() => ({})) as { message?: string };
    throw new Error(body.message ?? 'Failed to disconnect.');
  }
}
```

- [ ] **Step 4: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/api/
git commit -m "feat: add typed API domain modules (shop, analytics, settings)"
```

---

### Task 5: Create shared hooks

**Files:**
- Create: `resources/js/hooks/useShopify.ts`
- Create: `resources/js/hooks/useApiClient.ts`

- [ ] **Step 1: Create resources/js/hooks/useShopify.ts**

```typescript
// resources/js/hooks/useShopify.ts
import { createContext, useContext } from 'react';
import type { ClientApplication } from '../types/shopify';

export const ShopifyAppContext = createContext<ClientApplication | null>(null);

export function useShopify(): ClientApplication | null {
  return useContext(ShopifyAppContext);
}
```

- [ ] **Step 2: Create resources/js/hooks/useApiClient.ts**

```typescript
// resources/js/hooks/useApiClient.ts
import { useMemo } from 'react';
import { createApiClient, type ApiClient } from '../api/client';
import { useShopify } from './useShopify';

export function useApiClient(): ApiClient {
  const app = useShopify();
  return useMemo(() => createApiClient(app), [app]);
}
```

- [ ] **Step 3: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/hooks/
git commit -m "feat: add useShopify and useApiClient shared hooks"
```

---

### Task 6: Create shared UI components

**Files:**
- Create: `resources/js/components/ui/LoadingState.tsx`
- Create: `resources/js/components/ui/ErrorState.tsx`
- Create: `resources/js/components/ui/PageLayout.tsx`

- [ ] **Step 1: Create resources/js/components/ui/LoadingState.tsx**

```tsx
// resources/js/components/ui/LoadingState.tsx
import { Box, CircularProgress } from '@mui/material';

export function LoadingState() {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'center', mt: 8 }}>
      <CircularProgress />
    </Box>
  );
}
```

- [ ] **Step 2: Create resources/js/components/ui/ErrorState.tsx**

```tsx
// resources/js/components/ui/ErrorState.tsx
import { Alert, Box } from '@mui/material';

interface ErrorStateProps {
  message: string;
}

export function ErrorState({ message }: ErrorStateProps) {
  return (
    <Box sx={{ p: 3 }}>
      <Alert severity="error">{message}</Alert>
    </Box>
  );
}
```

- [ ] **Step 3: Create resources/js/components/ui/PageLayout.tsx**

```tsx
// resources/js/components/ui/PageLayout.tsx
import { Box, Button, Typography } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import type { ReactNode } from 'react';

interface PageLayoutProps {
  title: string;
  maxWidth?: number;
  backTo?: string;
  actions?: ReactNode;
  children: ReactNode;
}

export function PageLayout({ title, maxWidth = 720, backTo, actions, children }: PageLayoutProps) {
  const navigate = useNavigate();

  return (
    <Box sx={{ maxWidth, mx: 'auto', p: 3 }}>
      {backTo && (
        <Button variant="text" size="small" sx={{ mb: 2 }} onClick={() => navigate(backTo)}>
          ← Back
        </Button>
      )}
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 3 }}>
        <Typography variant="h6" fontWeight={700}>
          {title}
        </Typography>
        {actions}
      </Box>
      {children}
    </Box>
  );
}
```

- [ ] **Step 4: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/
git commit -m "feat: add shared UI components (LoadingState, ErrorState, PageLayout)"
```

---

### Task 7: Migrate features/home/

**Files:**
- Create: `resources/js/features/home/hooks/useShopStatus.ts`
- Create: `resources/js/features/home/HomePage.tsx`

- [ ] **Step 1: Create resources/js/features/home/hooks/useShopStatus.ts**

```typescript
// resources/js/features/home/hooks/useShopStatus.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../hooks/useApiClient';
import { getShopStatus } from '../../../api/shop';

export function useShopStatus() {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['shop-status'],
    queryFn: () => getShopStatus(apiClient),
  });
}
```

- [ ] **Step 2: Create resources/js/features/home/HomePage.tsx**

```tsx
// resources/js/features/home/HomePage.tsx
import { useNavigate } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Grid,
  Typography,
} from '@mui/material';
import { useShopStatus } from './hooks/useShopStatus';
import { LoadingState } from '../../components/ui/LoadingState';

const PLATFORMS = [
  { key: 'google_ads', label: 'Google Ads',  initial: 'G', color: '#4285F4', bg: '#e8f0fe', route: '/settings/google-ads' },
  { key: 'meta',       label: 'Meta',         initial: 'M', color: '#1877F2', bg: '#e7f3ff', route: '/settings/meta' },
  { key: 'tiktok',     label: 'TikTok',       initial: 'T', color: '#ffffff', bg: '#010101', route: '/settings/tiktok' },
  { key: 'ga4',        label: 'GA4',           initial: 'A', color: '#E37400', bg: '#fff3e0', route: '/settings/ga4' },
] as const;

export function HomePage() {
  const navigate = useNavigate();
  const { data, isLoading, error } = useShopStatus();

  if (isLoading) return <LoadingState />;

  if (error) {
    return (
      <Box sx={{ p: 3 }}>
        <Alert severity="error">{error.message}</Alert>
      </Box>
    );
  }

  const shop = data?.shop;
  const integrations = data?.integrations ?? {};

  return (
    <Box sx={{ maxWidth: 900, mx: 'auto', p: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
        <Typography variant="h5" fontWeight={700}>
          TrackFlow
        </Typography>
        <Button variant="outlined" size="small" onClick={() => navigate('/analytics')}>
          Analytics
        </Button>
      </Box>

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
                        width: 36, height: 36, borderRadius: '50%',
                        bgcolor: platform.bg,
                        display: 'flex', alignItems: 'center', justifyContent: 'center',
                        fontWeight: 700, fontSize: 14, color: platform.color,
                      }}
                    >
                      {platform.initial}
                    </Box>
                    <Box>
                      <Typography variant="body2" fontWeight={500}>{platform.label}</Typography>
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
```

- [ ] **Step 3: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/features/home/
git commit -m "feat: add features/home with useShopStatus hook and HomePage"
```

---

### Task 8: Migrate features/analytics/

**Files:**
- Create: `resources/js/features/analytics/components/ModeToggle.tsx`
- Create: `resources/js/features/analytics/components/DayNavigator.tsx`
- Create: `resources/js/features/analytics/components/DateRangePicker.tsx`
- Create: `resources/js/features/analytics/components/EventCountsTable.tsx`
- Create: `resources/js/features/analytics/hooks/useAnalytics.ts`
- Create: `resources/js/features/analytics/AnalyticsPage.tsx`

- [ ] **Step 1: Create resources/js/features/analytics/components/ModeToggle.tsx**

```tsx
// resources/js/features/analytics/components/ModeToggle.tsx
import { Button, ButtonGroup } from '@mui/material';
import type { AnalyticsMode } from '../../../types/api';

interface ModeToggleProps {
  mode: AnalyticsMode;
  onChange: (mode: AnalyticsMode) => void;
}

export function ModeToggle({ mode, onChange }: ModeToggleProps) {
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
```

- [ ] **Step 2: Create resources/js/features/analytics/components/DayNavigator.tsx**

```tsx
// resources/js/features/analytics/components/DayNavigator.tsx
import { Box, Button, Typography } from '@mui/material';

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

function formatDateLabel(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number);
  const d = new Date(year, month - 1, day);
  return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

function shiftDate(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number);
  const d = new Date(year, month - 1, day + days);
  return d.toISOString().slice(0, 10);
}

interface DayNavigatorProps {
  date: string;
  onChange: (date: string) => void;
}

export function DayNavigator({ date, onChange }: DayNavigatorProps) {
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
```

- [ ] **Step 3: Create resources/js/features/analytics/components/DateRangePicker.tsx**

```tsx
// resources/js/features/analytics/components/DateRangePicker.tsx
import { useState } from 'react';
import { Box, Button, TextField, Typography } from '@mui/material';

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

interface DateRangePickerProps {
  startDate: string;
  endDate: string;
  onApply: (start: string, end: string) => void;
}

export function DateRangePicker({ startDate, endDate, onApply }: DateRangePickerProps) {
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
    const days = Math.round(
      (new Date(localEnd).getTime() - new Date(localStart).getTime()) / 86400000,
    );
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
        onChange={(e) => { setLocalStart(e.target.value); setError(''); }}
        InputLabelProps={{ shrink: true }}
        inputProps={{ max: todayString() }}
      />
      <TextField
        label="End date"
        type="date"
        size="small"
        value={localEnd}
        onChange={(e) => { setLocalEnd(e.target.value); setError(''); }}
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
```

- [ ] **Step 4: Create resources/js/features/analytics/components/EventCountsTable.tsx**

```tsx
// resources/js/features/analytics/components/EventCountsTable.tsx
import {
  Box,
  CircularProgress,
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import type { EventCount } from '../../../types/api';

interface EventCountsTableProps {
  counts: EventCount[];
  total: number;
  loading: boolean;
}

export function EventCountsTable({ counts, total, loading }: EventCountsTableProps) {
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
          <TableCell><Typography variant="body2" fontWeight={600}>Event</Typography></TableCell>
          <TableCell align="right"><Typography variant="body2" fontWeight={600}>Count</Typography></TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {counts.map((row) => (
          <TableRow key={row.event}>
            <TableCell>
              <Typography variant="body2" color={row.count === 0 ? 'text.disabled' : 'text.primary'}>
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
          <TableCell><Typography variant="body2" fontWeight={700}>Total</Typography></TableCell>
          <TableCell align="right">
            <Typography variant="body2" fontWeight={700} sx={{ fontVariantNumeric: 'tabular-nums' }}>
              {total.toLocaleString()}
            </Typography>
          </TableCell>
        </TableRow>
      </TableFooter>
    </Table>
  );
}
```

- [ ] **Step 5: Create resources/js/features/analytics/hooks/useAnalytics.ts**

```typescript
// resources/js/features/analytics/hooks/useAnalytics.ts
import { useQuery } from '@tanstack/react-query';
import { useApiClient } from '../../../hooks/useApiClient';
import { getAnalytics } from '../../../api/analytics';
import type { AnalyticsParams } from '../../../types/api';

export function useAnalytics(params: AnalyticsParams) {
  const apiClient = useApiClient();
  return useQuery({
    queryKey: ['analytics', params],
    queryFn: () => getAnalytics(apiClient, params),
  });
}
```

- [ ] **Step 6: Create resources/js/features/analytics/AnalyticsPage.tsx**

```tsx
// resources/js/features/analytics/AnalyticsPage.tsx
import { useState } from 'react';
import { Alert, Box, Card, Typography } from '@mui/material';
import { PageLayout } from '../../components/ui/PageLayout';
import { ModeToggle } from './components/ModeToggle';
import { DayNavigator } from './components/DayNavigator';
import { DateRangePicker } from './components/DateRangePicker';
import { EventCountsTable } from './components/EventCountsTable';
import { useAnalytics } from './hooks/useAnalytics';
import type { AnalyticsMode, AnalyticsParams } from '../../types/api';

function todayString(): string {
  return new Date().toISOString().slice(0, 10);
}

export function AnalyticsPage() {
  const today = todayString();

  const [mode, setMode] = useState<AnalyticsMode>('single_day');
  const [date, setDate] = useState(today);
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);

  const params: AnalyticsParams =
    mode === 'single_day'
      ? { mode, date }
      : { mode, start_date: startDate, end_date: endDate };

  const { data, isFetching, error } = useAnalytics(params);

  const counts = data?.summary?.counts ?? [];
  const total = data?.summary?.total ?? 0;
  const period = data?.summary?.period ?? null;

  return (
    <PageLayout title="Analytics" maxWidth={800} backTo="/">
      {error && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {error.message}
        </Alert>
      )}

      <Card variant="outlined" sx={{ mb: 3, p: 2 }}>
        <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <ModeToggle mode={mode} onChange={setMode} />

          {mode === 'single_day' ? (
            <DayNavigator date={date} onChange={setDate} />
          ) : (
            <DateRangePicker
              startDate={startDate}
              endDate={endDate}
              onApply={(s, e) => { setStartDate(s); setEndDate(e); }}
            />
          )}

          {period && !isFetching && (
            <Typography variant="caption" color="text.secondary">
              Showing {period.label}{period.days > 1 ? ` (${period.days} days)` : ''}
            </Typography>
          )}
        </Box>
      </Card>

      <Card variant="outlined">
        <EventCountsTable counts={counts} total={total} loading={isFetching} />
      </Card>
    </PageLayout>
  );
}
```

- [ ] **Step 7: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add resources/js/features/analytics/
git commit -m "feat: add features/analytics with extracted components and useAnalytics hook"
```

---

### Task 9: Migrate features/settings/google-ads/

**Files:**
- Create: `resources/js/features/settings/google-ads/components/PermissionTroubleshootingPanel.tsx`
- Create: `resources/js/features/settings/google-ads/hooks/useGoogleAdsSettings.ts`
- Create: `resources/js/features/settings/google-ads/GoogleAdsForm.tsx`
- Create: `resources/js/features/settings/google-ads/GoogleAdsPage.tsx`

- [ ] **Step 1: Create PermissionTroubleshootingPanel.tsx**

```tsx
// resources/js/features/settings/google-ads/components/PermissionTroubleshootingPanel.tsx
import { Box, Link, Paper, Typography } from '@mui/material';

export function PermissionTroubleshootingPanel() {
  return (
    <Paper variant="outlined" sx={{ mt: 2, p: 2.5, borderColor: 'warning.light' }}>
      <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 2 }}>
        Troubleshooting — Permission Denied
      </Typography>
      <Box component="ol" sx={{ pl: 2.5, m: 0, display: 'flex', flexDirection: 'column', gap: 2.5 }}>
        <Box component="li">
          <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>
            Check your developer token access level
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Go to the{' '}
            <Link href="https://ads.google.com/aw/apicenter" target="_blank" rel="noopener">
              Google Ads API Center
            </Link>{' '}
            and check your developer token status. If it shows <strong>Test Account</strong>, it can
            only access special test accounts — not real Google Ads accounts.
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.75 }}>
            Apply for <strong>Standard Access</strong>, or create a{' '}
            <Link
              href="https://developers.google.com/google-ads/api/docs/first-call/test-accounts"
              target="_blank"
              rel="noopener"
            >
              test manager account
            </Link>{' '}
            and use its Customer ID instead.
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>
            Verify the OAuth account has access to the Customer ID
          </Typography>
          <Typography variant="body2" color="text.secondary">
            The Google account used to generate the OAuth Refresh Token must be an admin or user on
            the Customer ID account. In Google Ads, go to{' '}
            <strong>Settings → Account access</strong> and confirm the OAuth account email is listed
            there.
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>
            Confirm the Customer ID is linked to your MCC (if using an MCC Customer ID)
          </Typography>
          <Typography variant="body2" color="text.secondary">
            The Customer ID must be a sub-account under the MCC. In your MCC account, verify the
            Customer ID appears as a linked account.
          </Typography>
        </Box>
      </Box>
    </Paper>
  );
}
```

- [ ] **Step 2: Create hooks/useGoogleAdsSettings.ts**

```typescript
// resources/js/features/settings/google-ads/hooks/useGoogleAdsSettings.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import {
  getGoogleAdsSettings,
  saveGoogleAdsSettings,
  deleteGoogleAdsSettings,
} from '../../../../api/settings';
import type { GoogleAdsFormData } from '../../../../types/api';

const QUERY_KEY = ['settings', 'google-ads'] as const;

export function useGoogleAdsSettings() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => getGoogleAdsSettings(apiClient),
  });

  const saveMutation = useMutation({
    mutationFn: (data: GoogleAdsFormData) => saveGoogleAdsSettings(apiClient, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => deleteGoogleAdsSettings(apiClient),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  return { query, saveMutation, disconnectMutation };
}
```

- [ ] **Step 3: Create GoogleAdsForm.tsx**

```tsx
// resources/js/features/settings/google-ads/GoogleAdsForm.tsx
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material';
import { PermissionTroubleshootingPanel } from './components/PermissionTroubleshootingPanel';
import { useGoogleAdsSettings } from './hooks/useGoogleAdsSettings';
import { ValidationError } from '../../../types/api';
import type { GoogleAdsFormData } from '../../../types/api';

const schema = z.object({
  customer_id: z.string().min(1, 'Required'),
  mcc_id: z.string(),
  developer_token: z.string().min(1, 'Required'),
  oauth_client_id: z.string().min(1, 'Required'),
  oauth_client_secret: z.string().min(1, 'Required'),
  oauth_refresh_token: z.string().min(1, 'Required'),
});

const EMPTY_FORM: GoogleAdsFormData = {
  customer_id: '',
  mcc_id: '',
  developer_token: '',
  oauth_client_id: '',
  oauth_client_secret: '',
  oauth_refresh_token: '',
};

function isPermissionError(message: string): boolean {
  const lower = message.toLowerCase();
  return message.includes('403') || lower.includes('does not have permission');
}

interface GoogleAdsFormProps {
  onDisconnect: () => void;
  isDisconnecting: boolean;
}

export function GoogleAdsForm({ onDisconnect, isDisconnecting }: GoogleAdsFormProps) {
  const { query, saveMutation } = useGoogleAdsSettings();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const form = useForm<GoogleAdsFormData>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_FORM,
  });

  useEffect(() => {
    const creds = query.data?.credentials;
    if (creds) {
      form.reset({
        customer_id: creds.customer_id ?? '',
        mcc_id: creds.mcc_id ?? '',
        developer_token: creds.developer_token ?? '',
        oauth_client_id: creds.oauth?.client_id ?? '',
        oauth_client_secret: creds.oauth?.client_secret ?? '',
        oauth_refresh_token: creds.oauth?.refresh_token ?? '',
      });
    }
  }, [query.data, form]);

  async function onSubmit(data: GoogleAdsFormData) {
    setSuccessMessage(null);
    try {
      await saveMutation.mutateAsync(data);
      setSuccessMessage('Google Ads connected. Conversion actions are being created in the background.');
    } catch (err) {
      if (err instanceof ValidationError) {
        Object.entries(err.fieldErrors).forEach(([field, messages]) => {
          form.setError(field as keyof GoogleAdsFormData, {
            message: messages[0],
          });
        });
      }
    }
  }

  const apiError = saveMutation.error instanceof Error ? saveMutation.error.message : null;
  const mappings = query.data?.mappings ?? [];

  return (
    <>
      {successMessage && (
        <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccessMessage(null)}>
          {successMessage}
        </Alert>
      )}

      {apiError && !(apiError === 'Validation failed') && (
        <Box sx={{ mb: 3 }}>
          <Alert severity="error" onClose={() => saveMutation.reset()}>
            {apiError}
          </Alert>
          {isPermissionError(apiError) && <PermissionTroubleshootingPanel />}
        </Box>
      )}

      {form.formState.errors.root && (
        <Alert severity="error" sx={{ mb: 3 }}>
          {form.formState.errors.root.message}
        </Alert>
      )}

      <Card variant="outlined">
        <CardContent>
          <Box
            component="form"
            onSubmit={form.handleSubmit(onSubmit)}
            sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}
          >
            <TextField
              {...form.register('customer_id')}
              label="Customer ID"
              placeholder="123-456-7890"
              helperText={form.formState.errors.customer_id?.message ?? 'Your Google Ads account ID (not MCC)'}
              error={!!form.formState.errors.customer_id}
              fullWidth
              required
            />
            <TextField
              {...form.register('mcc_id')}
              label="MCC Customer ID (optional)"
              placeholder="123-456-7890"
              helperText={form.formState.errors.mcc_id?.message ?? "Leave blank if you don't use a manager account"}
              error={!!form.formState.errors.mcc_id}
              fullWidth
            />
            <TextField
              {...form.register('developer_token')}
              label="Developer Token"
              helperText={form.formState.errors.developer_token?.message}
              error={!!form.formState.errors.developer_token}
              fullWidth
              required
            />
            <TextField
              {...form.register('oauth_client_id')}
              label="OAuth Client ID"
              helperText={form.formState.errors.oauth_client_id?.message}
              error={!!form.formState.errors.oauth_client_id}
              fullWidth
              required
            />
            <TextField
              {...form.register('oauth_client_secret')}
              label="OAuth Client Secret"
              type="password"
              helperText={form.formState.errors.oauth_client_secret?.message}
              error={!!form.formState.errors.oauth_client_secret}
              fullWidth
              required
            />
            <TextField
              {...form.register('oauth_refresh_token')}
              label="OAuth Refresh Token"
              helperText={form.formState.errors.oauth_refresh_token?.message}
              error={!!form.formState.errors.oauth_refresh_token}
              fullWidth
              required
              multiline
              rows={3}
              inputProps={{ style: { fontFamily: 'monospace' } }}
            />
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
              <Button type="submit" variant="contained" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save & Connect'}
              </Button>
              {query.data?.integration?.active && (
                <Button
                  variant="outlined"
                  color="error"
                  size="small"
                  onClick={onDisconnect}
                  disabled={isDisconnecting}
                >
                  {isDisconnecting ? 'Disconnecting…' : 'Disconnect'}
                </Button>
              )}
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
    </>
  );
}
```

- [ ] **Step 4: Create GoogleAdsPage.tsx**

```tsx
// resources/js/features/settings/google-ads/GoogleAdsPage.tsx
import { Alert } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { GoogleAdsForm } from './GoogleAdsForm';
import { useGoogleAdsSettings } from './hooks/useGoogleAdsSettings';

export function GoogleAdsPage() {
  const { query, disconnectMutation } = useGoogleAdsSettings();

  if (query.isLoading) return <LoadingState />;

  if (query.error) {
    return (
      <PageLayout title="Google Ads Integration" backTo="/">
        <Alert severity="error">{query.error.message}</Alert>
      </PageLayout>
    );
  }

  async function handleDisconnect() {
    if (!window.confirm('Disconnect Google Ads? This will stop all conversion tracking.')) return;
    await disconnectMutation.mutateAsync();
  }

  return (
    <PageLayout title="Google Ads Integration" backTo="/">
      <GoogleAdsForm
        onDisconnect={handleDisconnect}
        isDisconnecting={disconnectMutation.isPending}
      />
    </PageLayout>
  );
}
```

- [ ] **Step 5: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add resources/js/features/settings/google-ads/
git commit -m "feat: add features/settings/google-ads with RHF form and TanStack Query"
```

---

### Task 10: Migrate features/settings/ga4/

**Files:**
- Create: `resources/js/features/settings/ga4/components/DisabledApiPanel.tsx`
- Create: `resources/js/features/settings/ga4/components/ScopeErrorPanel.tsx`
- Create: `resources/js/features/settings/ga4/hooks/useGa4Settings.ts`
- Create: `resources/js/features/settings/ga4/Ga4Form.tsx`
- Create: `resources/js/features/settings/ga4/Ga4Page.tsx`

- [ ] **Step 1: Create DisabledApiPanel.tsx**

```tsx
// resources/js/features/settings/ga4/components/DisabledApiPanel.tsx
import { Box, Link, Paper, Typography } from '@mui/material';

export function DisabledApiPanel() {
  return (
    <Paper variant="outlined" sx={{ mt: 2, p: 2, borderColor: 'warning.light' }}>
      <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 1 }}>
        Google Analytics Admin API is disabled
      </Typography>
      <Typography variant="body2" sx={{ mb: 1 }}>
        The Google Analytics Admin API must be enabled in your Google Cloud project.
      </Typography>
      <Box component="ol" sx={{ pl: 2.5, m: 0 }}>
        <Box component="li">
          <Typography variant="body2">
            Click this link:{' '}
            <Link
              href="https://console.developers.google.com/apis/api/analyticsadmin.googleapis.com/overview"
              target="_blank"
              rel="noopener"
            >
              Enable Analytics Admin API
            </Link>{' '}
            — make sure the correct project is selected
          </Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">Click <strong>Enable</strong></Typography>
        </Box>
        <Box component="li">
          <Typography variant="body2">Wait ~1 minute, then try again</Typography>
        </Box>
      </Box>
    </Paper>
  );
}
```

- [ ] **Step 2: Create ScopeErrorPanel.tsx**

```tsx
// resources/js/features/settings/ga4/components/ScopeErrorPanel.tsx
import { Box, Link, Paper, Typography } from '@mui/material';

export function ScopeErrorPanel() {
  return (
    <Paper variant="outlined" sx={{ mt: 2, p: 2, borderColor: 'warning.light' }}>
      <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 1 }}>
        Wrong OAuth scope
      </Typography>
      <Typography variant="body2" sx={{ mb: 1 }}>
        The OAuth Refresh Token must have the <code>analytics.edit</code> scope. Generate a new
        token at{' '}
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
```

- [ ] **Step 3: Create hooks/useGa4Settings.ts**

```typescript
// resources/js/features/settings/ga4/hooks/useGa4Settings.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useApiClient } from '../../../../hooks/useApiClient';
import {
  getGa4Settings,
  saveGa4Settings,
  deleteGa4Settings,
} from '../../../../api/settings';
import type { Ga4FormData } from '../../../../types/api';

const QUERY_KEY = ['settings', 'ga4'] as const;

export function useGa4Settings() {
  const apiClient = useApiClient();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: QUERY_KEY,
    queryFn: () => getGa4Settings(apiClient),
  });

  const saveMutation = useMutation({
    mutationFn: (data: Ga4FormData) => saveGa4Settings(apiClient, data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => deleteGa4Settings(apiClient),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: QUERY_KEY }),
  });

  return { query, saveMutation, disconnectMutation };
}
```

- [ ] **Step 4: Create Ga4Form.tsx**

```tsx
// resources/js/features/settings/ga4/Ga4Form.tsx
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Alert, Box, Button, Card, CardContent, TextField, Typography } from '@mui/material';
import { DisabledApiPanel } from './components/DisabledApiPanel';
import { ScopeErrorPanel } from './components/ScopeErrorPanel';
import { useGa4Settings } from './hooks/useGa4Settings';
import { ValidationError } from '../../../types/api';
import type { Ga4FormData } from '../../../types/api';

const schema = z.object({
  measurement_id: z.string().min(1, 'Required'),
  api_secret: z.string().min(1, 'Required'),
  property_id: z.string(),
  oauth_client_id: z.string(),
  oauth_client_secret: z.string(),
  oauth_refresh_token: z.string(),
});

const EMPTY_FORM: Ga4FormData = {
  measurement_id: '',
  api_secret: '',
  property_id: '',
  oauth_client_id: '',
  oauth_client_secret: '',
  oauth_refresh_token: '',
};

const DRAFT_KEY = 'trackflow_ga4_draft';
const DRAFT_FIELDS = ['property_id', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token'] as const;
type DraftField = (typeof DRAFT_FIELDS)[number];

function loadGa4Draft(): Partial<Pick<Ga4FormData, DraftField>> {
  try {
    return JSON.parse(localStorage.getItem(DRAFT_KEY) ?? '{}') as Partial<Pick<Ga4FormData, DraftField>>;
  } catch {
    return {};
  }
}

function saveGa4Draft(values: Pick<Ga4FormData, DraftField>): void {
  localStorage.setItem(DRAFT_KEY, JSON.stringify(values));
}

function isScopeError(message: string): boolean {
  const lower = message.toLowerCase();
  return lower.includes('insufficient') || lower.includes('scopes');
}

function isApiDisabledError(message: string): boolean {
  const lower = message.toLowerCase();
  return lower.includes('analyticsadmin') || lower.includes('analytics admin api') || lower.includes('has not been used');
}

interface Ga4FormProps {
  onDisconnect: () => void;
  isDisconnecting: boolean;
}

export function Ga4Form({ onDisconnect, isDisconnecting }: Ga4FormProps) {
  const { query, saveMutation } = useGa4Settings();
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const form = useForm<Ga4FormData>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_FORM,
  });

  useEffect(() => {
    const draft = loadGa4Draft();
    const creds = query.data?.credentials;
    if (creds || Object.keys(draft).length > 0) {
      form.reset({
        measurement_id: creds?.measurement_id ?? '',
        api_secret: creds?.api_secret ?? '',
        property_id: creds?.property_id || draft.property_id || '',
        oauth_client_id: creds?.oauth_client_id || draft.oauth_client_id || '',
        oauth_client_secret: creds?.oauth_client_secret || draft.oauth_client_secret || '',
        oauth_refresh_token: creds?.oauth_refresh_token || draft.oauth_refresh_token || '',
      });
    }
  }, [query.data, form]);

  useEffect(() => {
    const subscription = form.watch((value, { name }) => {
      if (name && (DRAFT_FIELDS as readonly string[]).includes(name)) {
        saveGa4Draft({
          property_id: value.property_id ?? '',
          oauth_client_id: value.oauth_client_id ?? '',
          oauth_client_secret: value.oauth_client_secret ?? '',
          oauth_refresh_token: value.oauth_refresh_token ?? '',
        });
      }
    });
    return () => subscription.unsubscribe();
  }, [form]);

  async function onSubmit(data: Ga4FormData) {
    setSuccessMessage(null);
    try {
      await saveMutation.mutateAsync(data);
      setSuccessMessage('GA4 connected. Event mappings are being configured in the background.');
      localStorage.removeItem(DRAFT_KEY);
    } catch (err) {
      if (err instanceof ValidationError) {
        Object.entries(err.fieldErrors).forEach(([field, messages]) => {
          form.setError(field as keyof Ga4FormData, { message: messages[0] });
        });
      }
    }
  }

  const apiError = saveMutation.error instanceof Error && !(saveMutation.error instanceof ValidationError)
    ? saveMutation.error.message
    : null;

  const isConnected = query.data?.connected ?? false;

  return (
    <>
      {successMessage && (
        <Alert severity="success" sx={{ mb: 3 }} onClose={() => setSuccessMessage(null)}>
          {successMessage}
        </Alert>
      )}

      {apiError && (
        <Box sx={{ mb: 3 }}>
          <Alert severity="error" onClose={() => saveMutation.reset()}>
            {apiError}
          </Alert>
          {isScopeError(apiError) && <ScopeErrorPanel />}
          {isApiDisabledError(apiError) && <DisabledApiPanel />}
        </Box>
      )}

      <Card variant="outlined">
        <CardContent>
          <Box
            component="form"
            onSubmit={form.handleSubmit(onSubmit)}
            sx={{ display: 'flex', flexDirection: 'column', gap: 2.5 }}
          >
            <TextField
              {...form.register('measurement_id')}
              label="Measurement ID"
              placeholder="G-XXXXXXXXXX"
              helperText={
                form.formState.errors.measurement_id?.message ??
                'Your GA4 property Measurement ID (e.g. G-XXXXXXXXXX)'
              }
              error={!!form.formState.errors.measurement_id}
              fullWidth
              required
            />
            <TextField
              {...form.register('api_secret')}
              label="API Secret"
              helperText={
                form.formState.errors.api_secret?.message ??
                'Create one in GA4: Admin → Data Streams → your stream → Measurement Protocol API secrets'
              }
              error={!!form.formState.errors.api_secret}
              fullWidth
              required
            />
            <Typography variant="body2" color="text.secondary">
              Optional: provide these to automatically create Key Events in your GA4 property.
            </Typography>
            <TextField
              {...form.register('property_id')}
              label="Property ID"
              helperText={
                form.formState.errors.property_id?.message ??
                'Numeric GA4 Property ID (Admin → Property Settings).'
              }
              error={!!form.formState.errors.property_id}
              fullWidth
            />
            <TextField
              {...form.register('oauth_client_id')}
              label="OAuth Client ID"
              helperText={
                form.formState.errors.oauth_client_id?.message ??
                'From Google Cloud Console → APIs & Services → Credentials'
              }
              error={!!form.formState.errors.oauth_client_id}
              fullWidth
            />
            <TextField
              {...form.register('oauth_client_secret')}
              label="OAuth Client Secret"
              type="password"
              helperText={form.formState.errors.oauth_client_secret?.message}
              error={!!form.formState.errors.oauth_client_secret}
              fullWidth
            />
            <TextField
              {...form.register('oauth_refresh_token')}
              label="OAuth Refresh Token"
              helperText={form.formState.errors.oauth_refresh_token?.message}
              error={!!form.formState.errors.oauth_refresh_token}
              fullWidth
              multiline
              rows={3}
            />
            <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
              <Button type="submit" variant="contained" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save & Connect'}
              </Button>
              {isConnected && (
                <Button
                  variant="outlined"
                  color="error"
                  size="small"
                  onClick={onDisconnect}
                  disabled={isDisconnecting}
                >
                  {isDisconnecting ? 'Disconnecting…' : 'Disconnect'}
                </Button>
              )}
            </Box>
          </Box>
        </CardContent>
      </Card>
    </>
  );
}
```

- [ ] **Step 5: Create Ga4Page.tsx**

```tsx
// resources/js/features/settings/ga4/Ga4Page.tsx
import { Alert } from '@mui/material';
import { PageLayout } from '../../../components/ui/PageLayout';
import { LoadingState } from '../../../components/ui/LoadingState';
import { Ga4Form } from './Ga4Form';
import { useGa4Settings } from './hooks/useGa4Settings';

export function Ga4Page() {
  const { query, disconnectMutation } = useGa4Settings();

  if (query.isLoading) return <LoadingState />;

  if (query.error) {
    return (
      <PageLayout title="Google Analytics 4 Integration" backTo="/">
        <Alert severity="error">{query.error.message}</Alert>
      </PageLayout>
    );
  }

  async function handleDisconnect() {
    if (!window.confirm('Disconnect GA4? This will stop all GA4 event tracking.')) return;
    await disconnectMutation.mutateAsync();
  }

  return (
    <PageLayout title="Google Analytics 4 Integration" backTo="/">
      <Ga4Form
        onDisconnect={handleDisconnect}
        isDisconnecting={disconnectMutation.isPending}
      />
    </PageLayout>
  );
}
```

- [ ] **Step 6: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add resources/js/features/settings/ga4/
git commit -m "feat: add features/settings/ga4 with RHF form, draft persistence, and TanStack Query"
```

---

### Task 11: Create ComingSoonPage and router

**Files:**
- Create: `resources/js/features/settings/ComingSoonPage.tsx`
- Create: `resources/js/router/index.tsx`

- [ ] **Step 1: Create resources/js/features/settings/ComingSoonPage.tsx**

```tsx
// resources/js/features/settings/ComingSoonPage.tsx
import { Box, Button, Typography } from '@mui/material';
import { useLocation, useNavigate } from 'react-router-dom';

export function ComingSoonPage() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const name = pathname
    .split('/')
    .pop()
    ?.replace('-', ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase()) ?? '';

  return (
    <Box sx={{ maxWidth: 600, mx: 'auto', p: 3, textAlign: 'center', mt: 8 }}>
      <Typography variant="h5" fontWeight={700} gutterBottom>
        {name} Integration
      </Typography>
      <Typography variant="body1" color="text.secondary" sx={{ mb: 3 }}>
        This integration is coming soon.
      </Typography>
      <Button variant="outlined" onClick={() => navigate('/')}>
        Back to Dashboard
      </Button>
    </Box>
  );
}
```

- [ ] **Step 2: Create resources/js/router/index.tsx**

```tsx
// resources/js/router/index.tsx
import { createBrowserRouter } from 'react-router-dom';
import { HomePage } from '../features/home/HomePage';
import { AnalyticsPage } from '../features/analytics/AnalyticsPage';
import { GoogleAdsPage } from '../features/settings/google-ads/GoogleAdsPage';
import { Ga4Page } from '../features/settings/ga4/Ga4Page';
import { ComingSoonPage } from '../features/settings/ComingSoonPage';

export const router = createBrowserRouter([
  { path: '/', element: <HomePage /> },
  { path: '/analytics', element: <AnalyticsPage /> },
  { path: '/settings/google-ads', element: <GoogleAdsPage /> },
  { path: '/settings/ga4', element: <Ga4Page /> },
  { path: '/settings/meta', element: <ComingSoonPage /> },
  { path: '/settings/tiktok', element: <ComingSoonPage /> },
]);
```

- [ ] **Step 3: Verify type check**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/features/settings/ComingSoonPage.tsx resources/js/router/
git commit -m "feat: add ComingSoonPage and centralized router"
```

---

### Task 12: Create main.tsx and switch entry point

**Files:**
- Create: `resources/js/main.tsx`
- Modify: `vite.config.js`

- [ ] **Step 1: Create resources/js/main.tsx**

```tsx
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
```

- [ ] **Step 2: Update vite.config.js to point to main.tsx**

Change the `input` entry in `vite.config.js`:

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/main.tsx'],
      refresh: true,
    }),
    react(),
  ],
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
    watch: {
      ignored: ['**/storage/framework/views/**'],
    },
  },
});
```

- [ ] **Step 3: Verify full type check passes**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors across all files.

- [ ] **Step 4: Verify build succeeds**

```bash
docker compose exec app yarn build
```

Expected: build completes, no TypeScript or Vite errors. Output in `public/build/`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/main.tsx vite.config.js
git commit -m "feat: add typed main.tsx with QueryClient provider and switch Vite entry point"
```

---

### Task 13: Delete old files

**Files:**
- Delete: `resources/js/app.js`
- Delete: `resources/js/api.js`
- Delete: `resources/js/main.jsx`
- Delete: `resources/js/pages/` (entire directory)

- [ ] **Step 1: Delete old files**

```bash
rm resources/js/app.js
rm resources/js/api.js
rm resources/js/main.jsx
rm -r resources/js/pages/
```

- [ ] **Step 2: Verify type check still passes**

```bash
docker compose exec app yarn tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Verify build still succeeds**

```bash
docker compose exec app yarn build
```

Expected: build completes without errors.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: remove legacy JS files (api.js, main.jsx, pages/) replaced by TypeScript"
```

---

## Final folder structure

```
resources/js/
├── main.tsx
├── router/
│   └── index.tsx
├── api/
│   ├── client.ts
│   ├── shop.ts
│   ├── analytics.ts
│   └── settings.ts
├── components/
│   └── ui/
│       ├── LoadingState.tsx
│       ├── ErrorState.tsx
│       └── PageLayout.tsx
├── features/
│   ├── home/
│   │   ├── HomePage.tsx
│   │   └── hooks/useShopStatus.ts
│   ├── analytics/
│   │   ├── AnalyticsPage.tsx
│   │   ├── components/
│   │   │   ├── ModeToggle.tsx
│   │   │   ├── DayNavigator.tsx
│   │   │   ├── DateRangePicker.tsx
│   │   │   └── EventCountsTable.tsx
│   │   └── hooks/useAnalytics.ts
│   └── settings/
│       ├── ComingSoonPage.tsx
│       ├── google-ads/
│       │   ├── GoogleAdsPage.tsx
│       │   ├── GoogleAdsForm.tsx
│       │   ├── components/PermissionTroubleshootingPanel.tsx
│       │   └── hooks/useGoogleAdsSettings.ts
│       └── ga4/
│           ├── Ga4Page.tsx
│           ├── Ga4Form.tsx
│           ├── components/
│           │   ├── DisabledApiPanel.tsx
│           │   └── ScopeErrorPanel.tsx
│           └── hooks/useGa4Settings.ts
├── hooks/
│   ├── useShopify.ts
│   └── useApiClient.ts
└── types/
    ├── api.ts
    └── shopify.ts
```
