# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **customized fork** of Invoice Ninja v5 with the KitchenPrinter module for Star mC-Print3 thermal printer integration.

- **Upstream Repository:** https://github.com/invoiceninja/invoiceninja
- **Our Fork:** https://github.com/88kbbq/invoiceninja
- **Production Branch:** `production` (auto-deploys to Digital Ocean App Platform)
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

## Digital Ocean App Platform Deployment

### Architecture

**Deployment Pipeline:**
```
GitHub (production branch)
    ↓ (auto-deploy on push)
Digital Ocean App Platform
    ↓ (builds & deploys)
Running Application
    ↓ (connects to)
Managed MySQL Database (dbaas-db-9899155)
```

**App Platform Configuration:**

- **App ID:** `c2183c5e-0f8d-4d94-9069-b225418d0fc2`
- **Region:** Singapore (sgp1)
- **Instance:** professional-xs
- **Database:** dbaas-db-9899155 (MySQL 8.0)
- **Config File:** `.do/app.yaml`

**Key Configuration Files:**

1. **`.do/app.yaml`** - App Platform specification
   - Defines services, databases, environment variables
   - Build and run commands
   - DO NOT modify buildpack selection manually (auto-detected)

2. **`nginx.conf`** - Nginx configuration (location blocks only)
   - Based on production server config at invoice.88k.com.tw
   - Heroku buildpack inserts this into their nginx template
   - Only include `location {}` directives, NOT `server {}` blocks

3. **`Procfile`** - Process definitions (if needed)
   - Currently using run_command in app.yaml instead

**Environment Variables:**

Set via Digital Ocean Dashboard → App → Settings → Environment Variables

Required:
```
APP_KEY=base64:... (generate with: php artisan key:generate --show)
APP_URL=https://invoice-ninja-production-xxxxx.ondigitalocean.app
```

Module-specific:
```
KITCHEN_PRINTER_ENABLED=false  (set to true when printer ready)
CLOUDPRNT_URL=http://printer-ip:8080/CloudPRNT
CLOUDPRNT_MAC=00:11:62:XX:XX:XX
```

Database (auto-populated from managed database):
```
DB_CONNECTION=mysql
DB_HOST=${dbaas-db-9899155.HOSTNAME}
DB_PORT=${dbaas-db-9899155.PORT}
DB_DATABASE=${dbaas-db-9899155.DATABASE}
DB_USERNAME=${dbaas-db-9899155.USERNAME}
DB_PASSWORD=${dbaas-db-9899155.PASSWORD}
```

**Common Commands:**

```bash
# List apps
doctl apps list

# Get app details
doctl apps get c2183c5e-0f8d-4d94-9069-b225418d0fc2

# View logs
doctl apps logs c2183c5e-0f8d-4d94-9069-b225418d0fc2 --follow

# Trigger manual deployment
doctl apps create-deployment c2183c5e-0f8d-4d94-9069-b225418d0fc2

# List deployments
doctl apps list-deployments c2183c5e-0f8d-4d94-9069-b225418d0fc2

# Database info
doctl databases get 025b9b40-3f0d-4ab6-b364-e0389b65e44a
```

### Deployment Process

**Automatic (on git push):**
1. Developer pushes to `production` branch
2. GitHub webhook triggers App Platform
3. App Platform clones repository
4. Runs build_command (composer install, npm build)
5. Creates container image
6. Runs run_command (migrations, start web server)
7. Routes traffic to new container (zero downtime)

**Build Command:**
```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --production
npm run build
```

**Run Command:**
```bash
php artisan module:enable KitchenPrinter || true
php artisan config:cache
php artisan route:cache
php artisan migrate --force --no-interaction
composer dump-autoload
heroku-php-nginx -C nginx.conf public/
```

**Important Notes:**
- App Platform uses **Heroku buildpacks** (not traditional LAMP stack)
- No `.env` file - environment variables injected directly
- No SSH access - debugging via logs only
- Use `heroku-php-nginx` NOT `php-fpm` directly
- Deployments take ~5-10 minutes
- Failed deployments auto-rollback

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

## Kitchen Printer Module

### Overview

Custom Laravel module for printing invoices/quotes to Star mC-Print3 thermal printer via CloudPRNT protocol.

**Location:** `Modules/KitchenPrinter/`

**Features:**
- Print individual invoices/quotes to kitchen printer
- Bulk print multiple items
- Kitchen receipt formatting (no prices - items and quantities only)
- JavaScript UI integration (auto-adds print buttons)
- CloudPRNT protocol support
- Star Document Markup formatting

### API Endpoints

```
POST   /api/v1/invoices/{id}/print_kitchen
POST   /api/v1/quotes/{id}/print_kitchen
POST   /api/v1/invoices/bulk_print_kitchen
GET    /api/v1/kitchen/test-connection
```

**Authentication:** Requires `X-API-TOKEN` header

**Example Request:**
```bash
curl -X POST "https://invoice.88k.com.tw/api/v1/invoices/WJxbojagwO/print_kitchen" \
  -H "X-API-TOKEN: your-token-here" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Content-Type: application/json"
```

**Example Response:**
```json
{
  "message": "Kitchen receipt sent successfully",
  "invoice_number": "880006",
  "print_job_id": "job-xyz-123"
}
```

### Module Architecture

```
KitchenPrinter/
├── app/
│   ├── Http/Controllers/
│   │   └── KitchenPrintController.php  # API endpoints
│   ├── Providers/
│   │   ├── KitchenPrinterServiceProvider.php  # Service registration
│   │   └── RouteServiceProvider.php           # Route registration
│   └── Services/
│       ├── CloudPRNTService.php        # Printer communication
│       └── KitchenPrintFormatter.php   # Receipt formatting
├── config/
│   └── config.php                      # Module configuration
├── routes/
│   └── api.php                         # API routes
└── module.json                         # Module metadata
```

**Key Services:**

1. **CloudPRNTService** (`app/Services/CloudPRNTService.php`)
   - Handles HTTP communication with printer
   - Implements CloudPRNT protocol
   - Sends Star Document Markup to printer

2. **KitchenPrintFormatter** (`app/Services/KitchenPrintFormatter.php`)
   - Formats invoice/quote data for printing
   - Generates Star Document Markup
   - Excludes prices (kitchen doesn't need pricing info)
   - Includes: order number, items, quantities, client info, event details

3. **KitchenPrintController** (`app/Http/Controllers/KitchenPrintController.php`)
   - API endpoint handlers
   - Authorization checks
   - Response formatting

### Configuration

**Environment Variables:**
```env
KITCHEN_PRINTER_ENABLED=false        # Enable/disable module
CLOUDPRNT_URL=                       # Printer CloudPRNT endpoint
CLOUDPRNT_MAC=                       # Printer MAC address
KITCHEN_PRINT_TEMPLATE=default       # Receipt template
```

**Module Config:** `Modules/KitchenPrinter/config/config.php`
```php
return [
    'enabled' => env('KITCHEN_PRINTER_ENABLED', false),
    'cloudprnt' => [
        'url' => env('CLOUDPRNT_URL', ''),
        'mac_address' => env('CLOUDPRNT_MAC', ''),
    ],
    'custom_fields' => [
        'event_time' => 'custom_value1',  # Invoice custom field for event time
        'event_date' => 'custom_value2',  # Invoice custom field for event date
    ],
    'include' => [
        'invoice_number' => true,
        'due_date' => true,
        'event_time' => true,
        'client_name' => true,
        'client_phone' => true,
        'item_descriptions' => true,
        'item_prices' => false,  # Kitchen doesn't need prices
        'totals' => false,       # Kitchen doesn't need totals
    ],
];
```

### UI Integration

**JavaScript Injection:** `public/modules/kitchen-printer/inject.js`

Automatically adds "Print to Kitchen" buttons to:
- Invoice action dropdowns
- Quote action dropdowns
- Bulk action toolbar

**How it works:**
1. Loaded conditionally when `KITCHEN_PRINTER_ENABLED=true`
2. Uses MutationObserver to detect invoice/quote pages
3. Injects print buttons into existing UI
4. Makes API calls to print endpoints
5. Shows toast notifications for feedback

**Template modification:** `resources/views/footer.blade.php`
```blade
@if(config('kitchenprinter.enabled'))
    <script src="{{ asset('modules/kitchen-printer/inject.js') }}"></script>
@endif
```

### Star CloudPRNT Protocol

**Overview:**
- HTTP-based protocol for cloud printing
- Star mC-Print3 thermal printer support
- Document formatting using Star Document Markup

**CloudPRNT Workflow:**
1. POST print job to printer URL
2. Printer responds with job ID
3. Upload print data (Star Document Markup) to job endpoint
4. Printer processes and prints

**Star Document Markup Example:**
```
[magnify: width 2; height 2]
[align: center]
*** KITCHEN ORDER ***
[magnify: width 1; height 1]
[align: left]
Order #: 880006
[cut: feed; partial]
```

**Resources:**
- CloudPRNT Documentation: https://www.star-m.jp/products/s_print/CloudPRNTSDK/
- Star Document Markup: https://www.star-m.jp/products/s_print/sdk/StarWebPrintSDK/Documentation/en/

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

# Digital Ocean App Platform
doctl apps logs c2183c5e-0f8d-4d94-9069-b225418d0fc2 --follow
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

2. **Push to production branch:**
   ```bash
   git push origin production  # Auto-deploys!
   ```

3. **Monitor deployment:**
   ```bash
   doctl apps logs c2183c5e-0f8d-4d94-9069-b225418d0fc2 --follow
   ```

4. **Verify deployment:**
   - Check app URL loads
   - Test critical functionality
   - Check for errors in logs

### Rollback Procedure

**Via Digital Ocean Dashboard:**
1. Go to Apps → invoice-ninja-production → Deployments
2. Find last working deployment
3. Click "Rollback"

**Via Git:**
```bash
git revert <bad-commit-hash>
git push origin production  # Deploys reverted code
```

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
- **App Platform Docs:** https://docs.digitalocean.com/products/app-platform/
- **Managed Databases:** https://docs.digitalocean.com/products/databases/
- **doctl CLI:** https://docs.digitalocean.com/reference/doctl/

### Star CloudPRNT
- **CloudPRNT SDK:** https://github.com/star-micronics/cloudprnt-sdk
- **Documentation:** https://www.star-m.jp/products/s_print/CloudPRNTSDK/Documentation/en/
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
   - Nginx logs (App Platform): via `doctl apps logs`
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

**Last Updated:** October 5, 2025
**Maintained By:** Development Team
**Repository:** https://github.com/88kbbq/invoiceninja
