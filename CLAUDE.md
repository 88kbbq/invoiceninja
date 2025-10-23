# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚠️ CRITICAL - SERVER IDENTIFICATION

**THIS PROJECT USES ONLY THE FOLLOWING SERVERS:**

- **Production Droplet:** 128.199.146.209 (invoice.88k.com.tw)
- **Database:** Managed MySQL (dbaas-db-9899155)
- **DO NOT TOUCH:** Any other Digital Ocean apps or servers on this account

**NEVER interact with, deploy to, or test against:**
- bbq-wolf-app (ac1d9c73-4d5f-4ba7-bb72-186cef914720) - This is a separate Payload CMS project
- Any other apps shown in `doctl apps list`

**ONLY work with the droplet at 128.199.146.209 for this Invoice Ninja project.**

---

## Project Overview

This is a **customized fork** of Invoice Ninja v5 with the KitchenPrinter module for Star mC-Print3 thermal printer integration.

- **Upstream Repository:** https://github.com/invoiceninja/invoiceninja
- **Our Fork:** https://github.com/88kbbq/invoiceninja
- **Production Branch:** `production` (auto-deploys to droplet 128.199.146.209)
- **Production URL:** https://invoice.88k.com.tw
- **Invoice Ninja Version:** v5.12.28+
- **Framework:** Laravel 11.46+
- **PHP Version:** 8.2+

---

## Repository Structure & Workflow

### Git Workflow

**Branch Strategy:**
```
88kbbq/invoiceninja (our fork)
├── production (our custom code - deploys to Digital Ocean)
├── v5-stable (tracks upstream Invoice Ninja)
└── upstream remote → invoiceninja/invoiceninja
```

**Working with the repository:**

```bash
# Clone the fork
git clone https://github.com/88kbbq/invoiceninja.git
cd invoiceninja

# Add upstream remote
git remote add upstream https://github.com/invoiceninja/invoiceninja.git
git fetch upstream

# Make changes
git checkout production
git pull origin production
# ... make your changes ...
git add .
git commit -m "Description of changes"
git push origin production  # ← Auto-deploys to Digital Ocean!
```

**Updating from upstream Invoice Ninja:**

```bash
# Fetch latest from Invoice Ninja
git fetch upstream

# Create update branch
git checkout production
git checkout -b update-invoice-ninja-$(date +%Y%m%d)

# Merge upstream changes
git merge upstream/v5-stable

# Resolve conflicts if needed (KitchenPrinter module should not conflict)
# Test locally
composer install
npm install
npm run build
php artisan migrate

# If tests pass, merge to production
git checkout production
git merge update-invoice-ninja-$(date +%Y%m%d)
git push origin production  # Triggers deployment
```

**IMPORTANT:** Our custom code lives in:
- `Modules/KitchenPrinter/` - Will NOT conflict with upstream updates
- `public/modules/kitchen-printer/` - Custom JavaScript
- `resources/views/footer.blade.php` - Small modification (may need merge)
- `.do/app.yaml` - Digital Ocean configuration
- `nginx.conf` - Web server configuration
- `scripts/setup-modules.sh` - Build scripts

---

## DigitalOcean Droplet Deployment

### Architecture

**Deployment Pipeline:**
```
Local development machine (production branch)
    ↓ (git push for history/auditing)
GitHub: 88kbbq/invoiceninja (production)
    ↓ (manual sync)
DigitalOcean Droplet 128.199.146.209 (/var/www/invoiceninja)
    ↓
Managed MySQL Database (dbaas-db-9899155)
```

**Droplet Details:**

- **Public IP / Hostname:** 128.199.146.209 (invoice.88k.com.tw)
- **Stack:** Ubuntu + Nginx + PHP 8.2 + Laravel 11.46+
- **Project Root:** `/var/www/invoiceninja`
- **React Bundles:** `/var/www/invoiceninja/public/react`
- **System User:** `www-data` owns web files; deploy as `root` or privileged user, then fix ownership.

**Critical Files & Directories:**

1. **`.env`** – Production secrets (APP_KEY, database credentials, API keys). Never commit or overwrite without backup.
2. **`app/Services/KitchenPrinterService.php`** – WebPRNT implementation.
3. **`config/kitchenprinter.php`** – Feature toggles and printer options.
4. **`resources/views/react/head.blade.php`** – References hashed React bundles.
5. **`nginx.conf`** – Reference config; actual live config lives under `/etc/nginx/`.
6. **`scripts/setup-modules.sh`** – Legacy helper (keep for history).

**Environment Variables:**

Stored in `/var/www/invoiceninja/.env`. Verify APP_KEY matches database encryption key before deploying. Module settings:
```
KITCHEN_PRINTER_ENABLED=false
KITCHEN_PRINTER_WEBPRNT_SCHEME=https
KITCHEN_PRINTER_WEBPRNT_IP=192.168.50.39    # Home test printer
KITCHEN_PRINTER_WEBPRNT_PORT=443
KITCHEN_PRINTER_WEBPRNT_PATH=/StarWebPRNT/SendMessage
KITCHEN_PRINTER_WEBPRNT_VERIFY_SSL=false
```

Database connection (already in `.env`):
```
DB_CONNECTION=mysql
DB_HOST=<managed-db-hostname>
DB_PORT=25060  # verify actual port
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

**Standard Backend Deployment Steps:**

1. Commit and push changes to `production` on GitHub for record keeping.
2. SSH to droplet: `ssh root@128.199.146.209`.
3. Navigate to project: `cd /var/www/invoiceninja`.
4. Pull latest code: `git pull origin production`.
5. Install PHP dependencies: `composer install --no-dev --optimize-autoloader --no-interaction`.
6. Run database migrations: `php artisan migrate --force --no-interaction`.
7. Refresh caches: `php artisan optimize:clear && php artisan optimize`.
8. If queue workers run, restart Supervisor/queue processes as needed.

**React UI Deployment (from local machine):**

1. In `~/InvoiceNinja/invoiceninja-ui`, run `npm install` (first time) then `npm run build`.
2. Rsync built bundles:
   ```bash
   rsync -avz --delete dist/react/ root@128.199.146.209:/var/www/invoiceninja/public/react/
   ```
3. Fix ownership: `ssh root@128.199.146.209 "chown -R www-data:www-data /var/www/invoiceninja/public/react/"`.
4. Clear caches: `ssh root@128.199.146.209 "cd /var/www/invoiceninja && php artisan optimize:clear"`.

**Services & Useful Commands:**

```bash
# Nginx and PHP-FPM status
systemctl status nginx
systemctl status php8.2-fpm

# Restart services after config changes
systemctl restart nginx
systemctl reload php8.2-fpm

# Laravel scheduler / queue (if configured)
php artisan queue:restart
php artisan schedule:run
```

**Maintenance Notes:**
- Always create `/root/backups/` snapshots (code, DB, .env) before major changes.
- Never overwrite `.env` without verifying APP_KEY.
- Keep `public/react/` in sync with latest build; hashes must match Blade template.
- Document any server-level changes (nginx, Supervisor, cron) in project notes.

---

## Invoice Ninja Architecture

### Request Flow

```
HTTP Request
  → Nginx (routes to public/index.php)
    → Laravel Router (routes/api.php, routes/web.php)
      → Middleware (auth, domain, locale)
        → Controller (app/Http/Controllers/)
          → Form Request (validation & authorization)
            → Repository (data access layer)
              → Service Layer (business logic)
                → Model (Eloquent ORM)
                  → Database
              ← Transformer (formats response)
            ← JSON Response
```

### Key Directories

```
app/
├── Console/          # Artisan commands
├── Events/           # Application events
├── Exceptions/       # Exception handlers
├── Factory/          # Model factories (e.g., InvoiceFactory)
├── Helpers/          # Helper functions
├── Http/
│   ├── Controllers/  # API & web controllers
│   ├── Middleware/   # Request middleware
│   ├── Requests/     # Form request validation
│   └── Livewire/     # Livewire components (admin UI)
├── Jobs/             # Queued background jobs
├── Listeners/        # Event listeners
├── Mail/             # Email notifications
├── Models/           # Eloquent models
├── PaymentDrivers/   # Payment gateway integrations
├── Repositories/     # Data access layer
├── Services/         # Business logic layer
└── Transformers/     # API response transformers (Fractal)

Modules/              # Custom modules (update-safe!)
└── KitchenPrinter/   # Our kitchen printer module
    ├── app/
    │   ├── Http/Controllers/
    │   ├── Providers/
    │   └── Services/
    ├── config/
    ├── routes/
    └── module.json

resources/
├── js/               # Vanilla JS for client portal
├── lang/             # Translation files
├── sass/             # SASS stylesheets
└── views/            # Blade templates (Livewire-based admin)

routes/
├── api.php           # API routes (Token auth)
├── client.php        # Client portal routes
└── web.php           # Admin web routes

public/               # Document root
└── modules/          # Public module assets
    └── kitchen-printer/
        └── inject.js # Kitchen printer UI integration
```

### Database Schema

- **Primary Keys:** Hashed string IDs (NOT integers) - use `hashed_id` column
- **Multi-tenancy:** All data scoped by `company_id`
- **Soft Deletes:** Most models use soft deletes
- **Relationships:** Defined in Models with Eloquent ORM

**Key Models:**
- `App\Models\Invoice` - Invoices
- `App\Models\Quote` - Quotes
- `App\Models\Client` - Customers
- `App\Models\Product` - Products/line items
- `App\Models\Company` - Multi-tenant company data
- `App\Models\User` - User accounts
- `App\Models\CompanyGateway` - Payment gateway configs

---

## Development Commands

### Setup and Installation

```bash
# Install dependencies (production)
composer install -o --no-dev

# Install dependencies (development - includes testing tools)
composer install -o

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Seed database with test data
php artisan migrate:fresh --seed
php artisan db:seed
php artisan ninja:create-test-data

# Start development server
php artisan serve
```

### Testing

```bash
# Run all tests
composer test
# OR
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Feature
./vendor/bin/phpunit --testsuite Integration

# Run single test file
./vendor/bin/phpunit tests/Feature/KitchenPrinterTest.php

# Run with coverage
composer test-coverage
```

### Code Quality

```bash
# Format code with PHP CS Fixer
composer format
# OR
composer pcf

# Lint code
composer lint
```

### Frontend Development

```bash
# Install dependencies
npm install

# Start Vite dev server
npm run dev

# Build for production
npm run build
# OR
npm run production
```

### Module Management

```bash
# List modules
php artisan module:list

# Enable module
php artisan module:enable KitchenPrinter

# Disable module
php artisan module:disable KitchenPrinter

# Create new module
php artisan module:make ModuleName

# Clear module cache
php artisan module:cache-clear
```

### Cache Management

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Rebuild caches (for production)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Autoload optimization
composer dump-autoload -o
```

---

## Kitchen Printer Integration

### Overview (January 2026)

Custom Laravel integration for printing invoices/quotes to a Star mC-Print3 thermal printer via **Star WebPRNT** over HTTPS. We currently validate against a **home-lab test printer** and will point the configuration to the work-location printer once production readiness is confirmed.

**Key Locations**
- Backend service: `app/Services/KitchenPrinterService.php`
- Controller: `app/Http/Controllers/KitchenPrinterController.php`
- Configuration: `config/kitchenprinter.php`
- API routes: `routes/api.php` (`kitchen-print/*`)
- React UI hooks: `invoiceninja-ui/src/pages/**/usePrintToKitchen.tsx`

**Features**
- Print single invoices or quotes via Star WebPRNT XML.
- Bulk print multiple invoices.
- Receipt formatting without prices/totals.
- Custom fields for event time/date.
- Test endpoints for connection + sample print.

### API Endpoints

```
POST   /api/v1/kitchen-print/invoice/{id}
POST   /api/v1/kitchen-print/quote/{id}
POST   /api/v1/kitchen-print/invoices/bulk
GET    /api/v1/kitchen/test-connection
GET    /api/v1/kitchen/test-print
POST   /api/v1/kitchen-print/invoice-webprnt/{id}   # Direct WebPRNT XML test
```

**Authentication:** `X-API-TOKEN` header required.

```bash
curl -X POST "https://invoice.88k.com.tw/api/v1/kitchen-print/invoice/WJxbojagwO"   -H "X-API-TOKEN: your-token-here"   -H "X-Requested-With: XMLHttpRequest"   -H "Content-Type: application/json"
```

### Backend Architecture

```
app/Services/KitchenPrinterService.php      # WebPRNT XML generation + HTTP client
app/Http/Controllers/KitchenPrinterController.php
config/kitchenprinter.php                  # Environment-driven settings
routes/api.php                             # Route definitions (kitchen-print group)
```

`KitchenPrinterService` exposes:
- `printInvoice()`, `printQuote()`, `bulkPrintInvoices()`
- `printInvoiceWebPRNTDirect()` for raw XML testing
- `testConnection()` and `testPrint()`

Logging: success and failure paths emit structured logs (`info` / `error`) with printer endpoint, payload length, and response code.

### Environment Variables (`.env`)

```env
KITCHEN_PRINTER_ENABLED=false
KITCHEN_PRINTER_IP=192.168.50.39                  # fallback IP
KITCHEN_PRINTER_PORT=9100                         # raw TCP (legacy support)
KITCHEN_PRINTER_WEBPRNT_SCHEME=https
KITCHEN_PRINTER_WEBPRNT_IP=192.168.50.39          # home test printer (replace for work site)
KITCHEN_PRINTER_WEBPRNT_PORT=443                  # 80 if printer only serves HTTP
KITCHEN_PRINTER_WEBPRNT_PATH=/StarWebPRNT/SendMessage
KITCHEN_PRINTER_WEBPRNT_VERIFY_SSL=false
KITCHEN_PRINTER_WEBPRNT_TIMEOUT=10
```

### Config Snapshot (`config/kitchenprinter.php`)

```php
return [
    'enabled' => env('KITCHEN_PRINTER_ENABLED', false),

    'tcp' => [
        'ip' => env('KITCHEN_PRINTER_IP', '192.168.50.39'),
        'port' => env('KITCHEN_PRINTER_PORT', 9100),
    ],

    'webprnt' => [
        'scheme' => env('KITCHEN_PRINTER_WEBPRNT_SCHEME', 'https'),
        'ip' => env('KITCHEN_PRINTER_WEBPRNT_IP', env('KITCHEN_PRINTER_IP', '192.168.50.39')),
        'port' => env('KITCHEN_PRINTER_WEBPRNT_PORT', 443),
        'path' => env('KITCHEN_PRINTER_WEBPRNT_PATH', '/StarWebPRNT/SendMessage'),
        'verify_ssl' => env('KITCHEN_PRINTER_WEBPRNT_VERIFY_SSL', false),
        'timeout' => env('KITCHEN_PRINTER_WEBPRNT_TIMEOUT', 10),
    ],

    'custom_fields' => [
        'event_time' => 'custom_value1',
        'event_date' => 'custom_value2',
    ],

    'include' => [
        'invoice_number' => true,
        'due_date' => true,
        'event_time' => true,
        'client_name' => true,
        'client_phone' => true,
        'item_descriptions' => true,
        'item_prices' => false,
        'totals' => false,
    ],
];
```

### UI Integration

**React Hooks:**
- `invoiceninja-ui/src/pages/invoices/common/hooks/usePrintToKitchen.tsx`
- `invoiceninja-ui/src/pages/quotes/common/hooks/usePrintToKitchen.tsx`

Each hook injects "Print to Kitchen" actions into the respective UI components and calls the `/api/v1/kitchen-print/...` endpoints. Buttons appear in the entity action menus plus the bulk action toolbar.

**Deployment Notes:**
1. Build UI: `npm run build` from `~/InvoiceNinja/invoiceninja-ui`.
2. Sync bundles: `rsync -avz --delete dist/react/ root@128.199.146.209:/var/www/invoiceninja/public/react/`.
3. Fix ownership + caches:
   ```bash
   ssh root@128.199.146.209 "chown -R www-data:www-data /var/www/invoiceninja/public/react/"
   ssh root@128.199.146.209 "cd /var/www/invoiceninja && php artisan optimize:clear"
   ```

### WebPRNT Primer

- WebPRNT is HTTPS-based; printer exposes `/StarWebPRNT/SendMessage`.
- We POST Star WebPRNT XML directly (no intermediate cloud job queues).
- Ensure printer certificates/SSL options align with `KITCHEN_PRINTER_WEBPRNT_VERIFY_SSL`.
- Home test printer currently reachable at `https://192.168.50.39/StarWebPRNT/SendMessage` (update for work site after rollout).

**Typical WebPRNT XML Snippet:**
```xml
<StarWebPRNT xmlns="http://www.star-m.jp">
  <Request>
    <Contents>
      <text emphasis="true" width="2" height="2">*** KITCHEN ORDER ***</text>
      <lineFeed/>
      <text>Order #: 880006</text>
      <lineFeed/>
      <cut type="partial"/>
    </Contents>
  </Request>
</StarWebPRNT>
```

### Testing & Troubleshooting

```bash
# Test connectivity
curl -X GET "https://invoice.88k.com.tw/api/v1/kitchen/test-connection"   -H "X-API-TOKEN: YOUR_API_TOKEN"   -H "X-Requested-With: XMLHttpRequest"

# Smoke test print payload
curl -X GET "https://invoice.88k.com.tw/api/v1/kitchen/test-print"   -H "X-API-TOKEN: YOUR_API_TOKEN"   -H "X-Requested-With: XMLHttpRequest"

# Direct WebPRNT print (bypasses TCP fallback)
curl -X POST "https://invoice.88k.com.tw/api/v1/kitchen-print/invoice-webprnt/WJxbojagwO"   -H "X-API-TOKEN: YOUR_API_TOKEN"   -H "X-Requested-With: XMLHttpRequest"
```

Troubleshooting order:
1. Check `.env` overrides (`KITCHEN_PRINTER_WEBPRNT_*`).
2. `tail -f /var/www/invoiceninja/storage/logs/laravel.log` while printing.
3. From droplet: `curl -k https://<printer-ip>/StarWebPRNT/SendMessage` to ensure reachability.
4. Confirm printer network path once moved from home test network to work location.

---

## PDF Generation

### Overview

Invoice Ninja v5 generates PDF invoices, quotes, and other documents using server-side rendering. The system supports multiple PDF engines with different capabilities.

**Current Configuration:** SnapPDF with Chromium browser (best for Chinese fonts)

### PDF Generator Options

1. **SnapPDF** (Default - RECOMMENDED)
   - Uses headless Chromium browser for rendering
   - ✅ Best quality for complex layouts
   - ✅ Excellent Chinese/CJK font support
   - ✅ Full CSS/modern web standards support
   - ⚠️ Requires Chromium binary installed
   - Package: `beganovich/snappdf`

2. **hosted_ninja**
   - Uses Invoice Ninja's cloud PDF service
   - ✅ No local dependencies required
   - ✅ Good Chinese font support
   - ⚠️ Sends invoice data to external service
   - ⚠️ Requires active internet connection

3. **phantom** (Legacy)
   - Uses PhantomJS service
   - ⚠️ Deprecated, not recommended
   - Moderate Chinese support

### System Requirements

**For SnapPDF (Production Setup):**

1. **Chromium Browser:**
   ```bash
   # Ubuntu/Debian
   apt-get update && apt-get install -y chromium-browser

   # Verify installation
   chromium --version
   ```

2. **Chinese Fonts (for CJK support):**
   ```bash
   # Ubuntu/Debian
   apt-get install -y fonts-noto-cjk

   # Verify fonts installed
   fc-list :lang=zh | head -10
   ```

3. **Environment Configuration:**
   ```env
   # .env
   PDF_GENERATOR=snappdf
   SNAPPDF_CHROMIUM_PATH=/snap/bin/chromium
   ```

**Production Server Status:**
- ✅ Chromium 141.0.7390.54 installed at `/snap/bin/chromium`
- ✅ Noto Sans CJK fonts installed (Simplified & Traditional Chinese)
- ✅ Noto Serif CJK fonts installed (Simplified & Traditional Chinese)
- ✅ SnapPDF configured in `.env`

### Configuration

**Environment Variables:**
```env
# PDF Generator engine (snappdf, hosted_ninja, phantom)
PDF_GENERATOR=snappdf

# Path to Chromium binary (required for SnapPDF)
SNAPPDF_CHROMIUM_PATH=/snap/bin/chromium

# Legacy PhantomJS settings (not used with SnapPDF)
PHANTOMJS_KEY='key'
PHANTOMJS_SECRET=secret
```

**Chromium Binary Locations:**
- Ubuntu Snap: `/snap/bin/chromium`
- Debian package: `/usr/bin/chromium-browser`
- Custom build: Specify full path in `SNAPPDF_CHROMIUM_PATH`

### How It Works

**SnapPDF Rendering Flow:**
```
Invoice Data
  → Laravel generates HTML
    → PdfService (app/Services/Pdf/PdfService.php)
      → SnapPDF library
        → Headless Chromium
          → Renders with system fonts
            → Returns PDF binary
              → Stored/downloaded
```

**Key Files:**
- `app/Services/Pdf/PdfService.php` - Main PDF service
- `app/Utils/Traits/Pdf/PdfMaker.php` - SnapPDF integration
- `app/Jobs/Entity/CreateRawPdf.php` - Background PDF generation

### Chinese Font Support

**Why SnapPDF is Best for Chinese:**

1. **Real Browser Rendering:**
   - Uses actual Chromium engine
   - Full Unicode support
   - Proper font fallback chains

2. **System Font Access:**
   - Automatically uses installed system fonts
   - Noto CJK fonts support all Chinese characters
   - Multiple weights (Regular, Bold, Black)

3. **Font Coverage:**
   - Simplified Chinese (SC)
   - Traditional Chinese (TC)
   - Japanese (JP)
   - Korean (KR)

**Installed Fonts on Production:**
```
/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc
/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc
/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc
/usr/share/fonts/opentype/noto/NotoSerifCJK-Regular.ttc
/usr/share/fonts/opentype/noto/NotoSerifCJK-Bold.ttc
```

### Troubleshooting

**Error: "Browser binary not found"**
```
Cause: Chromium not installed or path incorrect
Solution:
1. Install Chromium: apt-get install chromium-browser
2. Set path in .env: SNAPPDF_CHROMIUM_PATH=/snap/bin/chromium
3. Clear config cache: php artisan config:cache
```

**Error: Chinese characters show as boxes**
```
Cause: CJK fonts not installed
Solution:
1. Install fonts: apt-get install fonts-noto-cjk
2. Verify: fc-list :lang=zh
3. Restart PHP-FPM: systemctl reload php8.2-fpm
```

**Error: PDF generation is slow**
```
Cause: Chromium startup overhead
Solutions:
1. Use queue for background processing
2. Consider hosted_ninja for low-traffic sites
3. Optimize HTML templates (reduce complexity)
```

### Testing

**Generate test PDF:**
```bash
# Via Tinker
php artisan tinker
>>> $invoice = App\Models\Invoice::first();
>>> $invitation = $invoice->invitations->first();
>>> $pdf = (new App\Jobs\Entity\CreateRawPdf($invitation))->handle();
>>> file_put_contents('test.pdf', $pdf);
```

**Check configuration:**
```bash
php artisan tinker
>>> config('ninja.pdf_generator')  // Should return 'snappdf'
>>> config('ninja.snappdf_chromium_path')  // Should return '/snap/bin/chromium'
```

### Performance Optimization

**For high-volume PDF generation:**

1. **Use Queue Workers:**
   ```bash
   # config/queue.php
   'default' => 'redis',

   # Run workers
   php artisan queue:work --queue=default
   ```

2. **Cache Generated PDFs:**
   - PDFs are cached in `storage/app/public/`
   - Regenerate only when invoice changes
   - Set `DELETE_PDF_DAYS` in `.env` for cleanup

3. **Chromium Arguments:**
   - Already optimized in `PdfMaker.php`
   - Headless mode, no GPU, disabled features
   - Fast rendering for server environments

### Alternative: Using hosted_ninja

**Switch to cloud PDF generation:**
```env
# .env
PDF_GENERATOR=hosted_ninja
```

**Pros:**
- No Chromium installation needed
- No font management
- Faster for low-traffic sites

**Cons:**
- Invoice data sent to Invoice Ninja servers
- Requires internet connection
- Less control over rendering

---

## Best Coding Practices

### General Principles

1. **Follow Laravel Conventions**
   - Use Eloquent ORM (avoid raw queries)
   - Follow PSR-12 coding standards
   - Use dependency injection
   - Type hint all parameters and return types

2. **Service-Oriented Architecture**
   - Complex business logic belongs in Service classes
   - Controllers should be thin (just handle HTTP concerns)
   - Repositories for data access
   - Events for asynchronous operations

3. **Single Responsibility Principle**
   - Each class should have one clear purpose
   - Extract complex logic into separate classes
   - Keep methods focused and short

4. **Update-Safe Code**
   - ALL custom code in `Modules/` directory
   - Avoid modifying core Invoice Ninja files
   - If core modification needed, document thoroughly
   - Use Laravel's extension points (service providers, events)

### PHP Best Practices

```php
<?php

namespace App\Services\Example;

use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * Example service following best practices
 */
class ExampleService
{
    /**
     * Constructor injection for dependencies
     */
    public function __construct(
        private readonly SomeDependency $dependency
    ) {}

    /**
     * Type hints for parameters and return types
     *
     * @param Invoice $invoice
     * @return array
     */
    public function processInvoice(Invoice $invoice): array
    {
        try {
            // Guard clauses first
            if (!$invoice->exists) {
                throw new \InvalidArgumentException('Invoice must exist');
            }

            // Main logic
            $result = $this->dependency->doSomething($invoice);

            // Logging
            Log::info('Invoice processed', [
                'invoice_id' => $invoice->id,
                'result' => $result,
            ]);

            return [
                'success' => true,
                'data' => $result,
            ];

        } catch (\Exception $e) {
            // Error handling
            Log::error('Invoice processing failed', [
                'invoice_id' => $invoice->id ?? null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
```

**PHP Rules:**
- ✅ Use type hints everywhere (`string`, `int`, `array`, `?Type` for nullable)
- ✅ Use readonly properties (PHP 8.1+) where appropriate
- ✅ Use named arguments for clarity
- ✅ Use null coalescing `??` and null safe operator `?->`
- ✅ Use strict types: `declare(strict_types=1);`
- ❌ Avoid global functions (use services/helpers)
- ❌ Avoid magic methods unless necessary
- ❌ Never use `extract()` or `eval()`

### Laravel Best Practices

**Controllers:**
```php
class InvoiceController extends Controller
{
    // Dependency injection via constructor
    public function __construct(
        private readonly InvoiceRepository $repository
    ) {}

    // Form Request for validation/authorization
    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        // Service layer for business logic
        $invoice = $this->repository->save(
            $request->validated(),
            InvoiceFactory::create(
                auth()->user()->company()->id,
                auth()->user()->id
            )
        );

        // Service methods for complex operations
        $invoice = $invoice->service()
            ->fillDefaults()
            ->triggeredActions($request)
            ->save();

        // Events for async operations
        event(new InvoiceWasCreated($invoice, $invoice->company));

        // Transformer for response
        return $this->itemResponse($invoice);
    }
}
```

**Models:**
```php
class Invoice extends BaseModel
{
    // Mass assignment protection
    protected $guarded = ['id'];

    // Automatic casting
    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
        'is_deleted' => 'boolean',
    ];

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // Accessors (modern syntax)
    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn() => number_format($this->amount, 2)
        );
    }

    // Business logic via service
    public function service(): InvoiceService
    {
        return new InvoiceService($this);
    }
}
```

**Form Requests:**
```php
class PrintKitchenRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check permissions
        return auth()->user()->can('view', $this->route('invoice'));
    }

    public function rules(): array
    {
        return [
            'ids' => 'sometimes|array',
            'ids.*' => 'string|exists:invoices,id',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.*.exists' => 'One or more invoice IDs are invalid',
        ];
    }
}
```

### JavaScript Best Practices

```javascript
// Use strict mode
'use strict';

// Modern ES6+ syntax
class KitchenPrinter {
    constructor(apiUrl, token) {
        this.apiUrl = apiUrl;
        this.token = token;
    }

    async printInvoice(invoiceId) {
        try {
            // Use fetch API
            const response = await fetch(`${this.apiUrl}/invoices/${invoiceId}/print_kitchen`, {
                method: 'POST',
                headers: {
                    'X-API-TOKEN': this.token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                },
            });

            // Check response
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            this.showNotification(data.message, 'success');
            return data;

        } catch (error) {
            console.error('Print failed:', error);
            this.showNotification(error.message, 'error');
            throw error;
        }
    }

    showNotification(message, type) {
        // Use toast notification or similar
        console.log(`[${type.toUpperCase()}] ${message}`);
    }
}

// Use const/let, never var
const printer = new KitchenPrinter('/api/v1', 'token-here');

// Arrow functions for callbacks
document.querySelectorAll('.print-btn').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        e.preventDefault();
        const invoiceId = btn.dataset.invoiceId;
        await printer.printInvoice(invoiceId);
    });
});
```

**JavaScript Rules:**
- ✅ Use `const` by default, `let` when reassignment needed, never `var`
- ✅ Use arrow functions for callbacks
- ✅ Use `async/await` instead of `.then()` chains
- ✅ Use template literals for strings
- ✅ Use destructuring where appropriate
- ✅ Handle errors with try/catch
- ❌ Avoid global variables
- ❌ Don't modify prototypes of built-in objects
- ❌ Avoid `eval()` or `new Function()`

### Database/Migration Best Practices

```php
// Migrations - always reversible
public function up(): void
{
    Schema::create('kitchen_print_jobs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained();
        $table->string('job_id')->nullable();
        $table->string('status');
        $table->timestamps();

        // Indexes for performance
        $table->index(['invoice_id', 'created_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('kitchen_print_jobs');
}
```

**Database Rules:**
- ✅ Use migrations for all schema changes
- ✅ Add indexes for foreign keys and frequently queried columns
- ✅ Use appropriate column types (don't use `string` for everything)
- ✅ Use `timestamps()` for created_at/updated_at
- ✅ Use `softDeletes()` for data that should be recoverable
- ✅ Always write `down()` method for rollbacks
- ❌ Never modify production database directly
- ❌ Don't use `DB::raw()` unless absolutely necessary

### Testing Best Practices

```php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KitchenPrinterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test data
        $this->user = User::factory()->create();
        $this->invoice = Invoice::factory()->create();
    }

    /** @test */
    public function it_requires_authentication_to_print()
    {
        $response = $this->postJson(
            "/api/v1/invoices/{$this->invoice->hashed_id}/print_kitchen"
        );

        $response->assertStatus(401);
    }

    /** @test */
    public function it_can_print_invoice_when_authenticated()
    {
        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/v1/invoices/{$this->invoice->hashed_id}/print_kitchen"
            );

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'invoice_number',
            ]);
    }

    /** @test */
    public function it_returns_error_when_printer_not_configured()
    {
        config(['kitchenprinter.enabled' => false]);

        $response = $this->actingAs($this->user)
            ->postJson(
                "/api/v1/invoices/{$this->invoice->hashed_id}/print_kitchen"
            );

        $response->assertStatus(500)
            ->assertJsonFragment([
                'message' => 'Kitchen printer is disabled'
            ]);
    }
}
```

**Testing Rules:**
- ✅ Write tests for ALL new functionality
- ✅ Use `RefreshDatabase` trait for database tests
- ✅ Use factories for test data
- ✅ Test happy path AND error cases
- ✅ Use descriptive test names (`it_does_something` or `test_it_does_something`)
- ✅ Use `setUp()` for common test setup
- ❌ Don't test framework functionality (test YOUR code)
- ❌ Don't write tests that depend on each other

### Security Best Practices

1. **Authentication & Authorization**
   ```php
   // Always check permissions
   public function authorize(): bool
   {
       return $this->user()->can('view', $this->route('invoice'));
   }
   ```

2. **Input Validation**
   ```php
   // Validate ALL user input
   $validated = $request->validate([
       'amount' => 'required|numeric|min:0',
       'email' => 'required|email',
   ]);
   ```

3. **SQL Injection Prevention**
   ```php
   // ✅ Use Eloquent/Query Builder
   Invoice::where('client_id', $clientId)->get();

   // ❌ Never use raw queries with user input
   // DB::select("SELECT * FROM invoices WHERE id = {$id}"); // DANGEROUS!
   ```

4. **XSS Prevention**
   ```blade
   {{-- ✅ Blade automatically escapes --}}
   {{ $user->name }}

   {{-- ❌ Only use {!! !!} for trusted HTML --}}
   {!! $trustedHtml !!}
   ```

5. **CSRF Protection**
   ```blade
   <form method="POST">
       @csrf  {{-- Always include CSRF token --}}
   </form>
   ```

6. **API Security**
   - ✅ Require API token for all endpoints
   - ✅ Use rate limiting
   - ✅ Log all API access
   - ✅ Validate API token scope/permissions
   - ❌ Never expose sensitive data in API responses

---

## Invoice Ninja API

### Authentication

**API Token Authentication:**
```bash
curl -X GET "https://invoice.88k.com.tw/api/v1/invoices" \
  -H "X-API-TOKEN: your-token-here" \
  -H "X-Requested-With: XMLHttpRequest"
```

**Generate API Token:**
- Login to Invoice Ninja admin
- Settings → API Tokens
- Create Token
- Copy token (shown only once!)

### Common Endpoints

**Invoices:**
```
GET    /api/v1/invoices              # List invoices
GET    /api/v1/invoices/{id}         # Get single invoice
POST   /api/v1/invoices              # Create invoice
PUT    /api/v1/invoices/{id}         # Update invoice
DELETE /api/v1/invoices/{id}         # Delete invoice
POST   /api/v1/invoices/bulk         # Bulk operations
```

**Quotes:**
```
GET    /api/v1/quotes                # List quotes
GET    /api/v1/quotes/{id}           # Get single quote
POST   /api/v1/quotes                # Create quote
PUT    /api/v1/quotes/{id}           # Update quote
```

**Clients:**
```
GET    /api/v1/clients               # List clients
POST   /api/v1/clients               # Create client
```

**Response Format:**
```json
{
  "data": [
    {
      "id": "WJxbojagwO",
      "number": "0001",
      "amount": 100.00,
      "balance": 100.00,
      "client_id": "xyz123",
      ...
    }
  ],
  "meta": {
    "pagination": {
      "total": 100,
      "count": 20,
      "per_page": 20,
      "current_page": 1
    }
  }
}
```

**Important Notes:**
- Use **hashed_id** for API operations (NOT integer id)
- Include `X-Requested-With: XMLHttpRequest` header
- All dates in ISO 8601 format
- Amounts are in company's base currency

### API Documentation

**Official Docs:** https://api-docs.invoicing.co/

**Swagger/OpenAPI:** Available at `/docs` on your Invoice Ninja instance

---

## Common Tasks

### Creating a New Module

```bash
# Create module
php artisan module:make NewModuleName

# Module structure created at:
# Modules/NewModuleName/
```

**Then customize:**
1. Edit `module.json` - module metadata
2. Add controllers in `app/Http/Controllers/`
3. Add routes in `routes/api.php` or `routes/web.php`
4. Add service providers in `app/Providers/`
5. Register providers in `module.json`

### Adding New API Endpoint

1. **Create route** in `routes/api.php`:
   ```php
   Route::post('invoices/{invoice}/custom_action', [CustomController::class, 'action']);
   ```

2. **Create Form Request** in `app/Http/Requests/`:
   ```php
   class CustomActionRequest extends FormRequest
   {
       public function authorize(): bool { ... }
       public function rules(): array { ... }
   }
   ```

3. **Create Controller** in `app/Http/Controllers/`:
   ```php
   public function action(CustomActionRequest $request, Invoice $invoice)
   {
       // Business logic here
       return response()->json(['success' => true]);
   }
   ```

4. **Write tests** in `tests/Feature/`:
   ```php
   public function test_custom_action_works() { ... }
   ```

### Customizing Receipt Format

Edit: `Modules/KitchenPrinter/app/Services/KitchenPrintFormatter.php`

```php
protected function renderStarMarkup(array $data): string
{
    $markup = "[magnify: width 2; height 2]\n";
    $markup .= "[align: center]\n";
    $markup .= "*** KITCHEN ORDER ***\n";
    // ... add your custom formatting ...
    $markup .= "[cut: feed; partial]\n";
    return $markup;
}
```

**Star Document Markup Commands:**
- `[magnify: width X; height Y]` - Text sizing
- `[align: left|center|right]` - Alignment
- `[bold: on|off]` - Bold text
- `[cut: feed; partial]` - Cut paper
- `\n` - Line break

### Debugging

**Application logs:**
```bash
# Local development
tail -f storage/logs/laravel.log

# Production droplet (Laravel logs)
ssh root@128.199.146.209 "tail -f /var/www/invoiceninja/storage/logs/laravel.log"

# Nginx error log
ssh root@128.199.146.209 "tail -f /var/log/nginx/error.log"
```

**Enable debug mode (LOCAL ONLY):**
```env
APP_DEBUG=true
APP_ENV=local
```

**Never enable debug in production!**

**Laravel Telescope (if installed):**
```bash
php artisan telescope:install
php artisan migrate
# Visit /telescope
```

---

## Deployment Checklist

### Before Deploying

- [ ] All tests passing: `composer test`
- [ ] Code formatted: `composer format`
- [ ] No debug statements or `dd()` calls
- [ ] Environment variables documented
- [ ] Database migrations created (if needed)
- [ ] `.env.example` updated with new variables
- [ ] CLAUDE.md updated if architecture changed

### Deployment Process

1. **Commit changes:**
   ```bash
   git add .
   git commit -m "Descriptive commit message"
   ```

2. **Push to production branch (maintains GitHub history):**
   ```bash
   git push origin production
   ```

3. **Build and sync React bundles when UI changed:**
   ```bash
   cd ~/InvoiceNinja/invoiceninja-ui
   npm run build
   rsync -avz --delete dist/react/ root@128.199.146.209:/var/www/invoiceninja/public/react/
   ssh root@128.199.146.209 "chown -R www-data:www-data /var/www/invoiceninja/public/react/"
   ```

4. **Deploy backend code on droplet:**
   ```bash
   ssh root@128.199.146.209 <<'EOF'
   cd /var/www/invoiceninja
   git pull origin production
   composer install --no-dev --optimize-autoloader --no-interaction
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan optimize
   EOF
   ```

5. **Verify deployment:**
   - Load https://invoice.88k.com.tw (hard refresh after UI changes)
   - Exercise key workflows (login, invoices, KitchenPrinter flow)
   - Check logs: `tail -n 100 storage/logs/laravel.log` and `/var/log/nginx/error.log`

### Rollback Procedure

**Preferred (Git revert + redeploy):**
1. Identify bad commit hash.
2. Locally run `git revert <bad-commit-hash>` (or revert range).
3. Push to `production`: `git push origin production`.
4. SSH to droplet and pull latest: `cd /var/www/invoiceninja && git pull origin production`.
5. If the reverted change introduced migrations, run `php artisan migrate --force` (reverts run automatically when defined).

**Emergency (quick restore on droplet):**
```bash
ssh root@128.199.146.209
cd /var/www/invoiceninja
git checkout <last-known-good-hash>
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate:rollback --step=1  # if needed
php artisan optimize:clear
```
Follow up by resetting `production` branch to a good state and pushing so future pulls stay consistent.

---

## Resources

### Invoice Ninja Documentation
- **User Guide:** https://invoiceninja.github.io/en/user-guide/
- **Developer Guide:** https://invoiceninja.github.io/en/developer-guide/
- **API Documentation:** https://api-docs.invoicing.co/
- **Forum:** https://forum.invoiceninja.com
- **Slack:** http://slack.invoiceninja.com
- **GitHub:** https://github.com/invoiceninja/invoiceninja

### Laravel Documentation
- **Official Docs:** https://laravel.com/docs/11.x
- **Laravel News:** https://laravel-news.com
- **Laracasts (video tutorials):** https://laracasts.com

### Digital Ocean
- **Droplet Docs:** https://docs.digitalocean.com/products/droplets/
- **Managed Databases:** https://docs.digitalocean.com/products/databases/
- **Cloud Firewalls:** https://docs.digitalocean.com/products/networking/firewalls/
- **doctl CLI:** https://docs.digitalocean.com/reference/doctl/

### Star WebPRNT
- **WebPRNT SDK:** https://www.star-m.jp/products/s_print/StarWebPRNTSDK/index.html
- **XML Reference:** https://www.star-m.jp/products/s_print/StarWebPRNTSDK/Documents/
- **Star Document Markup:** https://www.star-m.jp/products/s_print/sdk/StarWebPrintSDK/Documentation/en/

### TapPay Payment Gateway
- **Main Documentation:** https://docs.tappaysdk.com
- **Developer Portal:** https://portal.tappaysdk.com (requires login)
- **Support:** support@cherri.tech
- **Payment Methods:** Direct Pay (Credit Cards), Electronic Payments (E-wallets), Token Pay (Apple/Google Pay)
- **Supported Networks:** Visa, Mastercard, JCB, AMEX, UnionPay
- **Integration:** Frontend tokenization (GetPrime) + Backend REST API
- **Analysis Report:** See TAPPAY_ANALYSIS_REPORT.md for implementation planning

### Development Tools
- **Laravel Modules:** https://nwidart.com/laravel-modules/
- **Fractal (API Transformers):** https://fractal.thephpleague.com/
- **Livewire:** https://livewire.laravel.com/
- **PHPUnit:** https://phpunit.de/

---

## Getting Help

### Troubleshooting Order

1. **Check logs first**
   - Application logs: `storage/logs/laravel.log`
   - Nginx logs (droplet): `/var/log/nginx/error.log`
   - Database logs: via Digital Ocean dashboard

2. **Check configuration**
   ```bash
   php artisan config:cache  # Rebuild config cache
   php artisan route:list    # List all routes
   php artisan module:list   # List modules
   ```

3. **Verify environment**
   ```bash
   php artisan about  # Show Laravel environment info
   php artisan env     # Show environment
   ```

4. **Test in isolation**
   ```bash
   php artisan tinker
   >>> $invoice = App\Models\Invoice::first()
   >>> $invoice->service()->doSomething()
   ```

5. **Check GitHub issues**
   - Our fork: https://github.com/88kbbq/invoiceninja/issues
   - Upstream: https://github.com/invoiceninja/invoiceninja/issues

6. **Ask for help**
   - Invoice Ninja Slack: http://slack.invoiceninja.com
   - Invoice Ninja Forum: https://forum.invoiceninja.com

---

## Important Reminders

### DO NOT:
- ❌ Modify core Invoice Ninja files (except when absolutely necessary)
- ❌ Commit `.env` file to git
- ❌ Store secrets in code (use environment variables)
- ❌ Enable debug mode in production
- ❌ Run migrations on production without backup
- ❌ Push directly to production without testing
- ❌ Ignore test failures
- ❌ Skip writing tests for new features

### ALWAYS:
- ✅ Put custom code in `Modules/` directory
- ✅ Use type hints and return types
- ✅ Write tests for new functionality
- ✅ Follow PSR-12 coding standards
- ✅ Use dependency injection
- ✅ Log important events
- ✅ Handle exceptions gracefully
- ✅ Document complex logic
- ✅ Update CLAUDE.md when architecture changes
- ✅ Test locally before deploying

---

## React UI Deployment

### Overview

The Invoice Ninja admin interface uses a React frontend (invoiceninja-ui) that is built separately and deployed to the Laravel backend's `public/` directory.

**Repository Structure:**
```
~/InvoiceNinja/
├── invoiceninja-ui/        # React frontend (separate repo)
│   ├── src/                # React source code
│   ├── dist/               # Build output (gitignored)
│   └── scripts/            # Build automation scripts
└── invoiceninja-fork/      # Laravel backend (main repo)
    ├── public/react/       # React bundles (gitignored)
    └── resources/views/react/
        └── head.blade.php  # Blade template that loads React bundles
```

### The Bundle Hash Problem

When Vite builds the React application, it generates bundle files with content-based hashes for cache busting:
- CSS: `index-BDngorsm.css`
- JS: `index-CukD1lb6.js`

These hashes **change every time the bundle content changes**. The Laravel backend needs to reference these files in a Blade template at `resources/views/react/head.blade.php`.

**The Issue:**
- React bundles are gitignored (`public/react` in `.gitignore`)
- Blade template IS committed to git
- If bundle hashes don't match, the app loads 404 or old JavaScript
- This causes subtle bugs where React code doesn't match backend expectations

### Automated Solution

We've created an automated post-build script that syncs bundle hashes.

**Script Location:** `invoiceninja-ui/scripts/update-blade-template.js`

**What It Does:**
1. Reads `dist/index.html` after Vite build
2. Extracts CSS and JS bundle hashes
3. Generates proper Blade syntax with `{{ asset() }}` helpers
4. Writes to `invoiceninja-fork/resources/views/react/head.blade.php`

**Automatic Execution:**
The script runs automatically after `npm run build`:

```json
{
  "scripts": {
    "build": "tsc && vite build && node scripts/update-blade-template.js"
  }
}
```

### Deployment Workflow

**1. Build React UI**
```bash
cd ~/InvoiceNinja/invoiceninja-ui
npm run build
# Script automatically updates ../invoiceninja-fork/resources/views/react/head.blade.php
```

**2. Copy Build Files**
```bash
# Copy React bundles to Laravel public directory
cp -r dist/react ../invoiceninja-fork/public/
cp dist/index.html ../invoiceninja-fork/public/
```

**3. Commit Both Repos**
```bash
# Commit UI changes
cd ~/InvoiceNinja/invoiceninja-ui
git add .
git commit -m "Update React UI: [description of changes]"
git push origin main

# Commit backend changes (including updated head.blade.php)
cd ~/InvoiceNinja/invoiceninja-fork
git add resources/views/react/head.blade.php
git commit -m "Update React bundle references"
git push origin production
```

**4. Deploy to Production**
```bash
# Pull backend changes (includes updated Blade template)
ssh root@128.199.146.209 "cd /var/www/invoiceninja && git pull origin production"

# Rsync React bundles (they're gitignored, so can't be pulled via git)
rsync -avz --delete ~/InvoiceNinja/invoiceninja-ui/dist/react/ \
  root@128.199.146.209:/var/www/invoiceninja/public/react/

# Fix ownership
ssh root@128.199.146.209 "chown -R www-data:www-data /var/www/invoiceninja/public/react/"

# Clear Laravel caches
ssh root@128.199.146.209 "cd /var/www/invoiceninja && php artisan optimize:clear"
```

### Common Issues

**Issue: 404 errors on React bundles**
- **Cause:** Blade template references old bundle hashes
- **Solution:** Run build script, verify `head.blade.php` was updated, commit and deploy

**Issue: Application loads but shows old React code**
- **Cause:** New Blade template deployed but old bundles still on server
- **Solution:** Rsync fresh bundles to production, clear browser cache (Ctrl+Shift+R)

**Issue: "Call to a member function format() on string"**
- **Cause:** Model date fields returned as strings instead of Carbon instances
- **Solution:** Use `Carbon::parse($model->date)->format()` instead of `$model->date->format()`
- **Fixed in:** `app/Services/KitchenPrinterService.php` (commit 8a51d37)

**Issue: Script shows "invoiceninja-fork directory not found"**
- **Cause:** Repos not in expected directory structure
- **Solution:** Ensure both repos are siblings in `~/InvoiceNinja/`

### Manual Blade Template Update

If the automated script fails, manually update the Blade template:

1. Check bundle hashes in `invoiceninja-ui/dist/index.html`:
   ```html
   <script type="module" crossorigin src="/react/index-CukD1lb6.js"></script>
   <link rel="stylesheet" crossorigin href="/react/index-BDngorsm.css">
   ```

2. Update `invoiceninja-fork/resources/views/react/head.blade.php`:
   ```blade
   <link rel="stylesheet" href="{{ asset('react/index-BDngorsm.css') }}">
   <script type="module" crossorigin src="{{ asset('react/index-CukD1lb6.js') }}"></script>
   ```

3. Clear view cache on production:
   ```bash
   php artisan view:clear
   ```

### Verification Checklist

After deploying React UI changes:

- [ ] Build completed successfully (`npm run build`)
- [ ] Blade template updated automatically
- [ ] Both repos committed and pushed
- [ ] Bundles rsync'd to production
- [ ] File ownership set to `www-data:www-data`
- [ ] Laravel caches cleared (`php artisan optimize:clear`)
- [ ] Browser hard refresh (Ctrl+Shift+R)
- [ ] Test affected functionality in production
- [ ] Check browser console for 404 errors

### Important Notes

- ✅ **Always** run the build script before deploying
- ✅ **Always** commit the updated `head.blade.php` to git
- ✅ **Always** use rsync to deploy bundles (they're gitignored)
- ❌ **Never** manually edit bundle hashes in `head.blade.php`
- ❌ **Never** commit `dist/` or `public/react/` directories to git
- ❌ **Never** modify React bundles directly on production server

---

## Critical Lessons Learned

### Backup Strategy
1. **Always backup codebase not on a versioning system**
   - Server snapshots may have old configuration files (`.env`, etc.)
   - Keep separate backups of critical files that aren't in git
   - Store backups in multiple locations (server + local machine)
   - Example: The November 4th snapshot had old APP_KEY, but `/root/backups/` had the correct one

2. **Maintain a secure credentials document**
   - Keep a list of ALL passwords, logins, keys, credentials, and sensitive data
   - Store in a project file that is NOT uploaded to GitHub
   - Include: APP_KEY, database passwords, API tokens, SSH keys, etc.
   - Update this file whenever credentials change
   - Consider using a password manager or encrypted vault

3. **Document encryption keys separately**
   - Laravel APP_KEY encrypts sensitive database data
   - If APP_KEY is lost, encrypted data (payment gateways, etc.) becomes unrecoverable
   - Always backup APP_KEY separately from code
   - When restoring from snapshots, verify APP_KEY matches what database expects

### Problem-Solving Protocol
**Keep to the script! If something doesn't work in three attempts:**
1. Stop trying variations of the same approach
2. Switch to Claude Opus model for deeper research
3. Ask Opus to analyze the root cause before attempting fixes
4. Document findings before implementing solution
5. Verify assumptions (e.g., check which APP_KEY the database actually uses)

**Example from this incident:**
- Multiple attempts to fix 500 errors by modifying code/config
- Should have stopped after 3 attempts and researched APP_KEY encryption
- Root cause was APP_KEY mismatch from snapshot restore
- Could have been identified faster with systematic investigation

### Deployment Safety
- Never overwrite working installations without verified backups
- Test deployment scripts on non-critical paths first
- Keep multiple restore points (not just latest snapshot)
- Verify critical configuration (APP_KEY, DB credentials) after restore
- Document the known-working state before making changes

---

**Last Updated:** October 7, 2025
**Maintained By:** Development Team
**Repository:** https://github.com/88kbbq/invoiceninja
