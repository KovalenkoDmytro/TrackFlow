# JS Frontend Refactoring Design

**Date:** 2026-05-23  
**Scope:** Full refactoring of `resources/js/` — TypeScript migration, feature-based structure, SOLID principles  
**Stack:** React 19 + React Router v7 + MUI v9 + Shopify App Bridge v3

---

## 1. Context

The current `resources/js/` is a Shopify embedded app SPA built with React. It has 5 pages, 0 reusable components, 7 inline sub-components never extracted, 0 custom hooks, and no type safety. All data fetching, form handling, and state management is duplicated across pages.

This is intentionally a standalone React SPA (not Vue/Inertia) — appropriate for Shopify embedded apps which require App Bridge and session token auth.

---

## 2. Goals

- Feature-based folder structure aligned with professional React conventions
- TypeScript throughout (`.tsx` / `.ts`)
- TanStack Query for all data fetching (replaces manual `useEffect` + `useState`)
- React Hook Form + Zod for all forms (replaces manual `useState` form boilerplate)
- Shared UI components eliminating duplication across pages
- Custom hooks decoupling data from presentation
- SOLID principles applied at the component and module level

---

## 3. New Dependencies

| Package | Version | Purpose |
|---|---|---|
| `@tanstack/react-query` | latest | Data fetching, caching, loading/error state |
| `react-hook-form` | latest | Form state management |
| `zod` | latest | Schema-based form validation |
| `@hookform/resolvers` | latest | Bridge between zod and react-hook-form |
| `typescript` | latest | Type safety |
| `@types/react` `@types/react-dom` | latest | React type definitions |

Existing packages remain: React 19, React Router v7, MUI v9, Shopify App Bridge v3, Emotion.

---

## 4. Folder Structure

```
resources/js/
├── main.tsx                     ← Bootstrap: QueryClient + ShopifyContext + Router
├── router/
│   └── index.tsx                ← Route definitions (extracted from main)
├── api/
│   ├── client.ts                ← createApiClient() — Shopify-aware fetch wrapper
│   ├── shop.ts                  ← getShopStatus(): Promise<ShopStatus>
│   ├── analytics.ts             ← getAnalytics(params): Promise<AnalyticsData>
│   └── settings.ts              ← getGoogleAds(), saveGoogleAds(), getGa4(), saveGa4()
├── components/
│   └── ui/
│       ├── LoadingState.tsx     ← Shared loading spinner
│       ├── ErrorState.tsx       ← Shared error display
│       └── PageLayout.tsx       ← Common page wrapper with title
├── features/
│   ├── home/
│   │   ├── HomePage.tsx
│   │   └── hooks/
│   │       └── useShopStatus.ts
│   ├── analytics/
│   │   ├── AnalyticsPage.tsx
│   │   ├── components/
│   │   │   ├── ModeToggle.tsx
│   │   │   ├── DayNavigator.tsx
│   │   │   ├── DateRangePicker.tsx
│   │   │   └── EventCountsTable.tsx
│   │   └── hooks/
│   │       └── useAnalytics.ts
│   └── settings/
│       ├── google-ads/
│       │   ├── GoogleAdsPage.tsx
│       │   ├── GoogleAdsForm.tsx
│       │   ├── components/
│       │   │   └── PermissionTroubleshootingPanel.tsx
│       │   └── hooks/
│       │       └── useGoogleAdsSettings.ts
│       └── ga4/
│           ├── Ga4Page.tsx
│           ├── Ga4Form.tsx
│           ├── components/
│           │   ├── DisabledApiPanel.tsx
│           │   └── ScopeErrorPanel.tsx
│           └── hooks/
│               └── useGa4Settings.ts
├── hooks/
│   └── useShopify.ts            ← useContext(ShopifyAppContext) shared hook
└── types/
    ├── api.ts                   ← ShopStatus, AnalyticsData, GoogleAdsSettings, Ga4Settings
    └── shopify.ts               ← ClientApplication type alias
```

---

## 5. Layer Responsibilities

### `api/client.ts`
Single Shopify-aware fetch wrapper. Takes `ClientApplication` instance, returns typed async function. Handles session token auth with cookie fallback. All API modules import from here.

```typescript
export function createApiClient(app: ClientApplication) {
  return async <T>(url: string, options?: RequestInit): Promise<T> => { ... }
}
```

### `api/*.ts` (domain modules)
Each module exports pure async functions. No React hooks. Receives `apiClient` function as parameter.

```typescript
// api/analytics.ts
export async function getAnalytics(
  client: ApiClient,
  params: AnalyticsParams
): Promise<AnalyticsData> { ... }
```

### `hooks/useShopify.ts`
Shared hook that reads from `ShopifyAppContext`. Components never call `useContext(ShopifyAppContext)` directly.

### `features/*/hooks/use*.ts`
Data hooks using TanStack Query. Return `{ data, isLoading, isError, error }`. Mutation hooks return `{ mutate, isPending }`.

```typescript
// features/analytics/hooks/useAnalytics.ts
export function useAnalytics(params: AnalyticsParams) {
  return useQuery({
    queryKey: ['analytics', params],
    queryFn: () => getAnalytics(apiClient, params),
  });
}
```

### `features/*/components/`
Pure presentational components. Receive data and callbacks as props. No direct API calls.

### `features/*/*Page.tsx`
Thin page components. Orchestrate layout, call feature hooks, pass data to components. No inline business logic.

### `features/settings/*Form.tsx`
React Hook Form + Zod. Schema defines shape and validation. `useSettingsHook` provides `onSubmit` mutation.

```typescript
const schema = z.object({ conversionId: z.string().min(1, 'Required') });
const { register, handleSubmit, formState: { errors } } = useForm({
  resolver: zodResolver(schema),
});
```

---

## 6. SOLID Principles Applied

| Principle | Application |
|---|---|
| **SRP** | Each file has one purpose: page (layout), form (input), hook (data), api module (HTTP) |
| **OCP** | New integration (Meta Ads) = new `features/settings/meta-ads/` with no changes to existing code |
| **LSP** | All page components accept same Router props contract |
| **ISP** | Hooks expose only what consumers need (`data`, `isLoading`, `mutate`) |
| **DIP** | Pages depend on hooks/API abstractions, not on `fetch` or `createApiFetch` directly |

---

## 7. TypeScript

- `tsconfig.json` added with `strict: true`, `jsx: react-jsx`
- All new files in `.tsx` / `.ts`
- API response types in `types/api.ts`
- No `any` — use `unknown` + type guards where needed

---

## 8. What Does NOT Change

- React Router v7 routes (same paths)
- MUI v9 components and theme
- Shopify App Bridge v3 initialization
- `ShopifyAppContext` React Context (just moved to `hooks/useShopify.ts`)
- Vite config (minor tsconfig addition only)
- Laravel backend routes and API endpoints

---

## 9. Migration Order

1. Add packages and `tsconfig.json`
2. Migrate `api/client.ts` from `api.js`
3. Create `types/api.ts` and `types/shopify.ts`
4. Create `hooks/useShopify.ts`
5. Create `api/shop.ts`, `api/analytics.ts`, `api/settings.ts`
6. Create `components/ui/` shared components
7. Migrate `features/home/`
8. Migrate `features/analytics/`
9. Migrate `features/settings/google-ads/` (includes `PermissionTroubleshootingPanel`)
10. Migrate `features/settings/ga4/` (includes `DisabledApiPanel`, `ScopeErrorPanel`)
10a. Migrate `features/settings/ComingSoonPage.tsx`
11. Create `router/index.tsx`
12. Refactor `main.tsx`
13. Delete `app.js` and original `api.js`
14. Run type-check and verify build
