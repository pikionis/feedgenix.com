# CLAUDE.md — FeedGenix Project Bootstrap

> **READ THIS FIRST.** This file instructs you to create a NEW project called FeedGenix, 
> using the existing Feed Intelligence codebase as a foundation. Do NOT modify the existing 
> project. Create everything in a NEW sibling directory.

---

## What You're Building

**FeedGenix** (feedgenix.com) — an AI-powered feed intelligence SaaS platform for e-commerce sellers.

Features: bulk product translation, AI field mapping, feed optimization, data enrichment, quality checks, multi-provider translation routing with smart caching.

Target users: cross-border e-commerce sellers (Shopify, Amazon, WooCommerce, PrestaShop).

Business model: freemium SaaS with 4 tiers (Free, Starter €29/mo, Pro €79/mo, Enterprise €199/mo).

---

## Step 1: Create New Project

Create a new directory NEXT TO this project:

```bash
cd ..
mkdir feedgenix
cd feedgenix
```

All work happens inside `../feedgenix/`. NEVER modify the current Feed Intelligence project.

---

## Step 2: Tech Stack

```
Backend:   Laravel 11 (PHP 8.3) — RESTful JSON API
Frontend:  React 18 + Vite + TypeScript + Tailwind CSS + shadcn/ui
Database:  PostgreSQL 16
Cache:     Redis 7
Auth:      Laravel Sanctum (SPA cookie auth)
Payments:  Laravel Cashier (Stripe)
Admin:     Filament 3 (at /admin, super_admin only)
State:     Zustand
Forms:     React Hook Form + Zod
Charts:    Recharts
```

Setup commands:

```bash
# Backend
composer create-project laravel/laravel backend
cd backend
composer require laravel/sanctum laravel/cashier filament/filament spatie/laravel-permission
php artisan filament:install --panels
cd ..

# Frontend
npm create vite@latest frontend -- --template react-ts
cd frontend
npm install tailwindcss @tailwindcss/vite
npm install zustand react-router-dom react-hook-form zod @hookform/resolvers
npm install recharts react-dropzone axios
npx shadcn@latest init
```

---

## Step 3: Port Services from Feed Intelligence

Copy these services from the CURRENT project into the new one. Refactor them to remove any Filament/supplier-specific dependencies and make them tenant-aware.

### MUST PORT (core business logic):

```
FROM: app/Services/ClaudeApiClient.php
  TO: backend/app/Services/AI/ClaudeApiClient.php
  CHANGES: Keep as-is, it's a clean API wrapper

FROM: app/Services/MappingService.php  
  TO: backend/app/Services/Mapping/FieldMappingService.php
  CHANGES: Remove supplier model references. Accept project_id instead.
           Input: array of CSV columns + sample rows
           Output: mapping JSON + confidence scores

FROM: app/Services/TranslatableFieldDetector.php
  TO: backend/app/Services/Mapping/TranslatableFieldDetector.php  
  CHANGES: Remove supplier references. Accept raw mapping + sample data.
           Return: fieldGroups[], skippedFields[], ambiguousFields[]

FROM: app/Services/TranslationRouter.php
  TO: backend/app/Services/Translation/TranslationRouter.php
  CHANGES: Add tenant-aware usage tracking. Check team subscription limits
           before routing. Keep provider chain logic (free first, paid fallback).

FROM: app/Services/TranslationCache.php (or equivalent caching logic)
  TO: backend/app/Services/Translation/TranslationCache.php
  CHANGES: Keep SHA256 hashing. Add team_id scoping to cache lookups.

FROM: app/Services/GoldenRuleValidator.php (or equivalent)
  TO: backend/app/Services/Validation/GoldenRuleValidator.php
  CHANGES: Keep exact same logic — NEVER modify prices, stock, barcodes, SKUs, 
           dimensions, weights.

FROM: app/Jobs/ (translation queue jobs)
  TO: backend/app/Jobs/
  CHANGES: Add team_id context. Add usage logging per job.
           Add checkpointing every 1000 products for large batches.
```

### DO NOT PORT:

- Filament pages, resources, widgets (building new ones)
- Supplier model and supplier-specific logic (Pertemba, Channable, etc.)
- Existing database migrations (new schema below)
- Existing routes and controllers
- Any Livewire components
- Weekly report / continuous sync logic (Phase 2)

---

## Step 4: Project Structure

```
feedgenix/
├── backend/                         # Laravel 11 API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   ├── Api/
│   │   │   │   │   ├── AuthController.php
│   │   │   │   │   ├── DashboardController.php
│   │   │   │   │   ├── ProjectController.php
│   │   │   │   │   ├── UploadController.php
│   │   │   │   │   ├── MappingController.php
│   │   │   │   │   ├── TranslationController.php
│   │   │   │   │   ├── ExportController.php
│   │   │   │   │   ├── SubscriptionController.php
│   │   │   │   │   └── UsageController.php
│   │   │   │   └── Webhook/
│   │   │   │       └── StripeWebhookController.php
│   │   │   ├── Middleware/
│   │   │   │   ├── CheckSubscription.php
│   │   │   │   ├── CheckFeature.php
│   │   │   │   ├── CheckUsageLimit.php
│   │   │   │   └── ScopeToTeam.php
│   │   │   └── Requests/            # Form request validation
│   │   ├── Models/
│   │   │   ├── User.php
│   │   │   ├── Team.php
│   │   │   ├── Project.php
│   │   │   ├── Upload.php
│   │   │   ├── FieldMapping.php
│   │   │   ├── Product.php
│   │   │   ├── Translation.php
│   │   │   ├── TranslationJob.php
│   │   │   ├── UsageLog.php
│   │   │   └── ApiKey.php
│   │   ├── Services/
│   │   │   ├── AI/
│   │   │   │   └── ClaudeApiClient.php
│   │   │   ├── Mapping/
│   │   │   │   ├── FieldMappingService.php
│   │   │   │   └── TranslatableFieldDetector.php
│   │   │   ├── Translation/
│   │   │   │   ├── TranslationRouter.php
│   │   │   │   ├── TranslationCache.php
│   │   │   │   └── Providers/
│   │   │   │       ├── TranslationProviderInterface.php
│   │   │   │       ├── MicrosoftTranslator.php
│   │   │   │       ├── DeepLTranslator.php
│   │   │   │       └── GrokTranslator.php
│   │   │   ├── Validation/
│   │   │   │   └── GoldenRuleValidator.php
│   │   │   ├── Export/
│   │   │   │   └── CsvExporter.php
│   │   │   ├── Billing/
│   │   │   │   ├── UsageTracker.php
│   │   │   │   └── SubscriptionGatekeeper.php
│   │   │   └── Import/
│   │   │       └── CsvParser.php
│   │   ├── Jobs/
│   │   │   ├── ProcessUpload.php
│   │   │   ├── RunFieldMapping.php
│   │   │   ├── RunTranslation.php
│   │   │   └── GenerateExport.php
│   │   ├── Policies/
│   │   │   ├── ProjectPolicy.php
│   │   │   └── TeamPolicy.php
│   │   └── Filament/                # Admin panel (super_admin only)
│   │       ├── Resources/
│   │       │   ├── UserResource.php
│   │       │   ├── TeamResource.php
│   │       │   └── TranslationJobResource.php
│   │       └── Widgets/
│   │           ├── RevenueWidget.php
│   │           ├── UsageWidget.php
│   │           └── ActiveUsersWidget.php
│   ├── config/
│   │   └── feedgenix.php            # Tier definitions, limits
│   ├── database/migrations/
│   └── routes/
│       ├── api.php
│       └── web.php                  # Filament admin only
│
├── frontend/                        # React 18 SPA
│   ├── src/
│   │   ├── api/
│   │   │   └── client.ts            # Axios instance with Sanctum
│   │   ├── components/
│   │   │   ├── ui/                  # shadcn/ui components
│   │   │   ├── layout/
│   │   │   │   ├── AppLayout.tsx
│   │   │   │   ├── Sidebar.tsx
│   │   │   │   └── TopBar.tsx
│   │   │   ├── upload/
│   │   │   │   ├── DropZone.tsx
│   │   │   │   └── UploadProgress.tsx
│   │   │   ├── mapping/
│   │   │   │   ├── MappingReview.tsx
│   │   │   │   └── FieldToggle.tsx
│   │   │   └── translation/
│   │   │       ├── TranslationProgress.tsx
│   │   │       └── TranslationPreview.tsx
│   │   ├── pages/
│   │   │   ├── public/
│   │   │   │   ├── Landing.tsx
│   │   │   │   ├── Pricing.tsx
│   │   │   │   └── FAQ.tsx
│   │   │   ├── auth/
│   │   │   │   ├── Login.tsx
│   │   │   │   ├── Register.tsx
│   │   │   │   └── ForgotPassword.tsx
│   │   │   ├── app/
│   │   │   │   ├── Dashboard.tsx
│   │   │   │   ├── Projects.tsx
│   │   │   │   ├── ProjectDetail.tsx
│   │   │   │   ├── NewProject.tsx    # Upload wizard
│   │   │   │   └── Settings.tsx
│   │   │   └── billing/
│   │   │       ├── Plans.tsx
│   │   │       └── BillingPortal.tsx
│   │   ├── hooks/
│   │   │   ├── useAuth.ts
│   │   │   ├── useProjects.ts
│   │   │   └── useSubscription.ts
│   │   ├── stores/
│   │   │   ├── authStore.ts
│   │   │   ├── projectStore.ts
│   │   │   └── uploadStore.ts
│   │   ├── lib/
│   │   │   └── utils.ts
│   │   ├── App.tsx
│   │   └── main.tsx
│   ├── index.html
│   └── tailwind.config.ts
│
└── docker-compose.yml               # PostgreSQL + Redis for local dev
```

---

## Step 5: Database Schema

Create these migrations in order:

```sql
-- 001: teams
CREATE TABLE teams (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    owner_id BIGINT NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    stripe_customer_id VARCHAR(255),
    plan VARCHAR(50) DEFAULT 'free',
    products_used_this_month INT DEFAULT 0,
    billing_cycle_reset_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 002: update users (add team_id)
ALTER TABLE users ADD COLUMN team_id BIGINT REFERENCES teams(id);
ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'member';
-- roles: super_admin, owner, member, viewer

-- 003: projects
CREATE TABLE projects (
    id BIGSERIAL PRIMARY KEY,
    team_id BIGINT NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    source_language VARCHAR(10),
    target_languages JSONB DEFAULT '[]',
    status VARCHAR(50) DEFAULT 'draft',
    -- status: draft, mapping, review, translating, completed, error
    products_count INT DEFAULT 0,
    last_upload_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_projects_team ON projects(team_id);

-- 004: uploads
CREATE TABLE uploads (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_hash VARCHAR(64),
    file_size BIGINT,
    row_count INT,
    column_count INT,
    column_names JSONB,
    sample_rows JSONB,
    status VARCHAR(50) DEFAULT 'pending',
    -- status: pending, processing, analyzed, error
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

-- 005: field_mappings
CREATE TABLE field_mappings (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    upload_id BIGINT REFERENCES uploads(id),
    mapping JSONB NOT NULL DEFAULT '{}',
    translatable_fields JSONB DEFAULT '[]',
    protected_fields JSONB DEFAULT '[]',
    skipped_fields JSONB DEFAULT '[]',
    ambiguous_fields JSONB DEFAULT '[]',
    detected_source_language VARCHAR(10),
    ai_confidence DECIMAL(3,2),
    ai_model VARCHAR(100),
    user_approved BOOLEAN DEFAULT FALSE,
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- 006: products
CREATE TABLE products (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    external_id VARCHAR(255),
    row_number INT,
    source_data JSONB NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    is_new BOOLEAN DEFAULT TRUE,
    has_changes BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_products_project ON products(project_id);
CREATE INDEX idx_products_hash ON products(content_hash);
CREATE INDEX idx_products_external ON products(project_id, external_id);

-- 007: translations
CREATE TABLE translations (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    field_name VARCHAR(255) NOT NULL,
    source_text TEXT NOT NULL,
    source_hash VARCHAR(64) NOT NULL,
    target_language VARCHAR(10) NOT NULL,
    translated_text TEXT,
    provider VARCHAR(100),
    quality_score DECIMAL(3,2),
    is_cached BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_translations_lookup ON translations(source_hash, target_language);
CREATE INDEX idx_translations_product ON translations(product_id);

-- 008: translation_jobs
CREATE TABLE translation_jobs (
    id BIGSERIAL PRIMARY KEY,
    project_id BIGINT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    team_id BIGINT NOT NULL REFERENCES teams(id),
    status VARCHAR(50) DEFAULT 'queued',
    -- status: queued, processing, completed, failed, cancelled
    total_products INT DEFAULT 0,
    processed_products INT DEFAULT 0,
    total_translations INT DEFAULT 0,
    completed_translations INT DEFAULT 0,
    cached_translations INT DEFAULT 0,
    failed_translations INT DEFAULT 0,
    estimated_cost DECIMAL(10,4) DEFAULT 0,
    actual_cost DECIMAL(10,4) DEFAULT 0,
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

-- 009: usage_logs
CREATE TABLE usage_logs (
    id BIGSERIAL PRIMARY KEY,
    team_id BIGINT NOT NULL REFERENCES teams(id),
    action VARCHAR(100) NOT NULL,
    -- actions: upload, mapping, translation, export, api_call
    quantity INT DEFAULT 1,
    metadata JSONB DEFAULT '{}',
    billing_period VARCHAR(7), -- '2026-04'
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_usage_team_period ON usage_logs(team_id, billing_period);

-- 010: api_keys
CREATE TABLE api_keys (
    id BIGSERIAL PRIMARY KEY,
    team_id BIGINT NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    key_hash VARCHAR(64) NOT NULL UNIQUE,
    key_prefix VARCHAR(10) NOT NULL, -- 'fg_live_' first 8 chars for identification
    last_used_at TIMESTAMP,
    expires_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
```

---

## Step 6: API Routes

```php
// routes/api.php

// Public
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Authenticated (Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    // Team-scoped routes
    Route::middleware(['scope.team'])->group(function () {
        
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/usage', [UsageController::class, 'index']);

        // Projects
        Route::apiResource('projects', ProjectController::class);

        // Upload (requires active subscription)
        Route::middleware(['check.subscription'])->group(function () {
            Route::post('/projects/{project}/upload', [UploadController::class, 'store']);
        });

        // Mapping
        Route::get('/projects/{project}/mapping', [MappingController::class, 'show']);
        Route::put('/projects/{project}/mapping', [MappingController::class, 'update']);

        // Translation (requires subscription + usage check)
        Route::middleware(['check.subscription', 'check.usage'])->group(function () {
            Route::post('/projects/{project}/translate', [TranslationController::class, 'start']);
        });
        Route::get('/projects/{project}/status', [TranslationController::class, 'status']);
        Route::get('/projects/{project}/preview', [TranslationController::class, 'preview']);

        // Export
        Route::get('/projects/{project}/export', [ExportController::class, 'download']);

        // Subscription
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::post('/subscription', [SubscriptionController::class, 'create']);
        Route::put('/subscription', [SubscriptionController::class, 'update']);
        Route::delete('/subscription', [SubscriptionController::class, 'cancel']);
        Route::get('/subscription/portal', [SubscriptionController::class, 'portal']);
    });
});

// Stripe webhook (no auth)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
```

---

## Step 7: Config — Subscription Tiers

```php
// config/feedgenix.php
return [
    'tiers' => [
        'free' => [
            'products_per_month' => 100,
            'target_languages' => 1,
            'team_members' => 1,
            'features' => ['csv_upload', 'basic_mapping', 'csv_export'],
        ],
        'starter' => [
            'price_monthly' => 2900,
            'price_yearly' => 27840,
            'stripe_monthly_id' => env('STRIPE_STARTER_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_STARTER_YEARLY'),
            'products_per_month' => 1000,
            'target_languages' => 3,
            'team_members' => 2,
            'features' => ['csv_upload', 'smart_mapping', 'csv_export',
                          'xlsx_export', 'change_detection'],
        ],
        'pro' => [
            'price_monthly' => 7900,
            'price_yearly' => 75840,
            'stripe_monthly_id' => env('STRIPE_PRO_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_PRO_YEARLY'),
            'products_per_month' => 10000,
            'target_languages' => 10,
            'team_members' => 5,
            'features' => ['csv_upload', 'smart_mapping', 'csv_export',
                          'xlsx_export', 'json_export', 'xml_export',
                          'change_detection', 'continuous_sync',
                          'api_access', 'priority_support'],
        ],
        'enterprise' => [
            'price_monthly' => 19900,
            'price_yearly' => 191040,
            'stripe_monthly_id' => env('STRIPE_ENTERPRISE_MONTHLY'),
            'stripe_yearly_id' => env('STRIPE_ENTERPRISE_YEARLY'),
            'products_per_month' => PHP_INT_MAX,
            'target_languages' => PHP_INT_MAX,
            'team_members' => PHP_INT_MAX,
            'features' => ['*'],
        ],
    ],

    'golden_rule_patterns' => [
        'price', 'prix', 'preis', 'precio', 'prezzo', 'cost',
        'stock', 'quantity', 'qty', 'inventory', 'bestand',
        'ean', 'upc', 'gtin', 'barcode', 'isbn',
        'sku', 'ref', 'reference', 'article_number',
        'weight', 'width', 'height', 'length', 'depth',
        'dimension', 'poids', 'gewicht',
    ],
];
```

---

## Step 8: Design System

```css
/* Frontend theme — use with shadcn/ui */
:root {
  --background: 0 0% 100%;
  --foreground: 222 47% 16%;
  --primary: 166 100% 42%;          /* #00d4aa — electric teal */
  --primary-foreground: 0 0% 100%;
  --secondary: 222 47% 16%;         /* deep navy */
  --secondary-foreground: 0 0% 100%;
  --accent: 166 50% 95%;
  --accent-foreground: 166 100% 25%;
  --muted: 210 20% 96%;
  --muted-foreground: 215 16% 47%;
  --destructive: 0 84% 60%;
  --border: 220 13% 91%;
  --radius: 0.5rem;
}
```

Fonts: Plus Jakarta Sans (headings) + DM Sans (body) — Google Fonts.

---

## Step 9: Execution Order

Do this in sequence. Commit after each step.

### Phase A — Scaffolding (do first)
1. Create project directories (backend + frontend)
2. Install all dependencies
3. Set up docker-compose.yml (PostgreSQL 16 + Redis 7)
4. Create all database migrations
5. Create Eloquent models with relationships
6. Set up Sanctum authentication
7. Set up basic API routing
8. Set up React project with Tailwind + shadcn/ui + routing
9. Build API client (axios with Sanctum CSRF)

### Phase B — Port Core Services
10. Copy and refactor services from Feed Intelligence (see Step 3)
11. Make all services tenant-aware (accept team_id)
12. Write feature tests for ported services

### Phase C — Build the Pipeline
13. CSV upload endpoint + file processing
14. AI field mapping (triggers after upload)
15. Mapping review API (get/approve/edit mapping)
16. Translation job queue
17. Translation progress tracking
18. CSV export/download

### Phase D — Frontend
19. Landing page (beautiful, conversion-optimized)
20. Auth pages (login, register, forgot password)
21. Dashboard
22. Projects list + create
23. Upload wizard (drag-drop → mapping review → translate → download)
24. Settings page
25. Pricing page + Stripe checkout

### Phase E — Billing & Admin
26. Stripe integration (Laravel Cashier)
27. Subscription middleware (check plan, check features, check usage)
28. Usage tracking
29. Filament admin panel (users, teams, revenue, usage)

### Phase F — Polish
30. Error handling + validation
31. Email templates (welcome, translation complete, usage warning)
32. SEO (meta tags, sitemap, robots.txt)
33. Mobile responsiveness
34. Performance optimization

---

## Core Business Rules — NEVER VIOLATE

1. **Golden Rule:** NEVER modify prices, stock, barcodes, SKUs, dimensions, weights. These pass through untouched.

2. **Translation Cache:** SHA256(source_text + target_language + provider). Always check cache before calling any provider.

3. **Change Detection:** Hash each product row. On re-upload, only re-translate changed products.

4. **Feature Gating:** Every endpoint checks: authenticated → active subscription → feature allowed → within usage limits.

5. **Tenant Isolation:** Every query scoped to team_id. Admin panel bypasses this.

6. **Free Tier First:** Translation router tries free providers (Microsoft 2M chars/mo, etc.) before using paid providers.

---

## Environment Variables Needed

```env
# App
APP_NAME=FeedGenix
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=feedgenix
DB_USERNAME=feedgenix
DB_PASSWORD=secret

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Sanctum
SANCTUM_STATEFUL_DOMAINS=localhost:5173

# Stripe
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_STARTER_MONTHLY=price_...
STRIPE_STARTER_YEARLY=price_...
STRIPE_PRO_MONTHLY=price_...
STRIPE_PRO_YEARLY=price_...
STRIPE_ENTERPRISE_MONTHLY=price_...
STRIPE_ENTERPRISE_YEARLY=price_...

# AI
ANTHROPIC_API_KEY=sk-ant-...
CLAUDE_MODEL=claude-sonnet-4-20250514

# Translation Providers
MICROSOFT_TRANSLATOR_KEY=...
MICROSOFT_TRANSLATOR_REGION=westeurope
DEEPL_API_KEY=...
GROK_API_KEY=...

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=hello@feedgenix.com
MAIL_FROM_NAME=FeedGenix
```

---

*Start with Phase A. Do NOT skip ahead. Commit after each numbered step.*
