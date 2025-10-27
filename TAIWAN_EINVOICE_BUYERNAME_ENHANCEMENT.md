# Taiwan E-Invoice BuyerName Enhancement Plan

**Date Created:** 2025-10-27
**Status:** Phase 1 Complete ✅ | Phase 2 Planned 📋
**Priority:** Medium (Working solution in place, enhancement for better UX)

---

## 🎯 Overview

This document tracks the evolution of the BuyerName field handling in Taiwan E-Invoice integration with Amego API.

### The Problem
- **Original Issue:** Sending `$client->name` (customer input) as BuyerName for B2B invoices
- **Why It's Wrong:** Customer-entered company names are often incorrect or incomplete
- **API Requirement:** Per Amego documentation, when company name is unknown, send the VAT number (統一編號) instead

### API Documentation Reference
```
買方名稱 (BuyerName)
1. 不打統編：可以填寫客人、消費者
   (No VAT: Can fill "guest" or "consumer")

2. 打統編：如不能填入買方公司名稱，請填買方統一編號
   (With VAT: If you cannot fill buyer company name, fill the VAT number)

3. 不可填0、00、 000及0000
   (Cannot fill 0, 00, 000, or 0000)
```

---

## ✅ Phase 1: Immediate Fix (COMPLETED)

### Implementation Date
2025-10-27

### Changes Made
**File:** `/var/www/invoiceninja/app/Services/TaiwanEInvoice/TaiwanEInvoiceService.php`
**Line:** 226

**Before:**
```php
'BuyerName' => $isB2B ? $client->name : '客人',
```

**After:**
```php
'BuyerName' => $isB2B ? $buyerGui : '客人',
```

### Logic Flow
- **B2C Invoice (no VAT number):**
  - `BuyerIdentifier` = `'0000000000'`
  - `BuyerName` = `'客人'` (guest)

- **B2B Invoice (with VAT number):**
  - `BuyerIdentifier` = VAT number (e.g., `'83464574'`)
  - `BuyerName` = VAT number (e.g., `'83464574'`)
  - Amego will accept this and process the invoice

### Why This Works
- Follows Amego's documented fallback: "或是帶入統編" (or use the VAT number)
- Works for regular companies, non-profits, and schools
- No API lookup required (faster, simpler)
- No risk of lookup failures blocking invoice issuance

### Testing Checklist
- [x] Deploy to production
- [ ] Test B2C invoice (no VAT) → Should show "客人"
- [ ] Test B2B invoice (with VAT) → Should show VAT number
- [ ] Verify Amego accepts the invoice
- [ ] Check receipt displays correctly in Amego dashboard

---

## 🚀 Phase 2: Company Name Lookup Enhancement (PLANNED)

### Goal
Automatically fetch and display correct company names instead of showing VAT numbers.

### Available APIs

#### 1. Amego Company Lookup API ⭐
**Endpoint:** `POST /json/ban_query`
**Base URL:** `https://invoice-api.amego.tw`
**Authentication:** Same as e-invoice API (invoice, data, time, sign)

**Request Format:**
```json
[
    {
        "ban": "28080623"
    },
    {
        "ban": "85101991"
    }
]
```

**Response Format:**
```json
{
    "code": 0,
    "msg": "success",
    "data": [
        {
            "ban": "28080623",
            "company_name": "台灣某某股份有限公司"
        },
        {
            "ban": "85101991",
            "company_name": "某某有限公司"
        }
    ]
}
```

**Limitations:**
- ❌ Does NOT support non-profit organizations
- ❌ Does NOT support schools
- ✅ Only supports for-profit companies

#### 2. Taiwan Government API (財政部) 🏛️
**URL:** https://eip.fia.gov.tw/OAI/swagger-ui.html
**Source:** 財政部財政資訊中心

**Coverage:**
- ✅ For-profit companies
- ✅ Non-profit organizations
- ✅ Schools
- ✅ Government entities

**API Endpoints:**
- Company lookup
- VAT validation
- Business registration info

**Benefits:**
- More comprehensive than Amego API
- Free government service
- Authoritative source

**Considerations:**
- May require separate authentication
- Need to review API documentation
- Rate limiting unknown

---

## 📊 Phase 2 Implementation Options

### Option 1: Auto-fill in Invoice Ninja Client Form ⭐ RECOMMENDED

#### Description
When user enters a VAT number in the client form, automatically fetch and populate the company name.

#### User Flow
1. User creates/edits a client in Invoice Ninja
2. User enters VAT number in "VAT Number" field
3. **On blur/save** → System calls company lookup API
4. If found → Auto-populate "Name" field with official company name
5. If not found → Show warning "Company not found, please enter manually"
6. User can review and override the auto-filled name if needed
7. Company name is saved permanently in Invoice Ninja database

#### Technical Implementation
**Frontend (React UI):**
- Add onBlur handler to VAT number field in client form
- Call new backend API endpoint: `GET /api/v1/clients/lookup-company/{vat_number}`
- Display loading state while fetching
- Show success/error feedback
- Auto-populate name field with response

**Backend (Laravel):**
- Create new controller method: `ClientController@lookupCompany()`
- Create service: `CompanyLookupService.php`
- Try Amego API first (fastest, already integrated)
- Fallback to Taiwan Government API if Amego fails
- Cache results for 30 days (company names rarely change)
- Return JSON: `{ "success": true, "company_name": "..." }`

**Files to Modify:**
```
Frontend:
- invoiceninja-ui/src/pages/clients/edit/Edit.tsx
- invoiceninja-ui/src/pages/clients/components/ClientForm.tsx

Backend:
- invoiceninja-fork/app/Http/Controllers/ClientController.php (add route)
- invoiceninja-fork/app/Services/CompanyLookupService.php (new file)
- invoiceninja-fork/routes/api.php (add route)
```

**Pros:**
- ✅ Permanent data storage (used for all invoices)
- ✅ One-time lookup per client (efficient)
- ✅ User can verify before saving
- ✅ Works offline after initial lookup
- ✅ Improves Invoice Ninja data quality

**Cons:**
- ❌ Requires both frontend and backend changes
- ❌ Most complex implementation (2-3 hours)
- ❌ Needs cache management

**Estimated Time:** 2-3 hours

---

### Option 2: Lookup Button in Taiwan Receipt Modal

#### Description
Add a "Lookup Company Name" button in the Issue Taiwan Receipt modal.

#### User Flow
1. User clicks "Issue Taiwan Receipt"
2. Modal shows VAT number field with "🔍 Lookup Company Name" button
3. User enters VAT → clicks button
4. System fetches company name from API
5. Displays company name in read-only field
6. User proceeds with issuance using fetched name

#### Technical Implementation
**Frontend (React UI):**
- Modify `IssueTaiwanReceiptModal.tsx`
- Add company name display field (read-only)
- Add lookup button with loading state
- Call backend API: `POST /api/v1/payments/lookup-company`

**Backend (Laravel):**
- Add method to `TaiwanReceiptController.php`
- Reuse `CompanyLookupService.php` from Option 1
- Return JSON: `{ "success": true, "company_name": "..." }`
- Modify `issueReceipt()` to accept `$companyName` parameter

**Files to Modify:**
```
Frontend:
- invoiceninja-ui/src/pages/payments/common/components/IssueTaiwanReceiptModal.tsx

Backend:
- invoiceninja-fork/app/Http/Controllers/TaiwanReceiptController.php
- invoiceninja-fork/app/Services/CompanyLookupService.php (new file)
- invoiceninja-fork/app/Services/TaiwanEInvoice/TaiwanEInvoiceService.php
```

**Pros:**
- ✅ Simple UI addition
- ✅ User sees what name will be sent
- ✅ Fresh lookup every time (always current)
- ✅ Moderate complexity (1 hour)

**Cons:**
- ❌ Extra step for users
- ❌ Doesn't update Invoice Ninja client record
- ❌ Lookup on every issuance (slower)

**Estimated Time:** 1 hour

---

### Option 3: Auto-lookup During E-Invoice Issuance

#### Description
Automatically lookup company name in the backend when issuing e-invoice.

#### User Flow
1. User clicks "Issue Taiwan Receipt" (no changes to UI)
2. Backend automatically calls company lookup API
3. Uses returned name if found, VAT number if not
4. Issues e-invoice with best available name
5. User sees success message

#### Technical Implementation
**Backend Only:**
- Modify `TaiwanEInvoiceService.php`
- Add `lookupCompanyName($gui)` method
- Call it before building request data
- Use result if successful, fallback to VAT number

**Code Changes:**
```php
// Add to TaiwanEInvoiceService.php

protected function lookupCompanyName(string $gui): ?string
{
    try {
        // Try Amego API first
        $response = $this->makeRequest('/json/ban_query', [
            ['ban' => $gui]
        ]);

        if (isset($response['data'][0]['company_name'])) {
            return $response['data'][0]['company_name'];
        }

        // Could add Taiwan Government API fallback here

        return null;
    } catch (Exception $e) {
        \Log::warning('Company name lookup failed', [
            'gui' => $gui,
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

// In issueReceipt() method:
if ($isB2B) {
    // Try to lookup company name from API
    $buyerName = $this->lookupCompanyName($buyerGui);

    // Fallback to VAT number if lookup fails
    if (empty($buyerName)) {
        $buyerName = $buyerGui;
        \Log::info('Using VAT number as BuyerName (lookup failed)', [
            'gui' => $buyerGui
        ]);
    }
} else {
    $buyerName = '客人';
}
```

**Pros:**
- ✅ Fully automatic (best UX)
- ✅ No UI changes required
- ✅ Simplest implementation (30 minutes)
- ✅ Always gets latest company name

**Cons:**
- ❌ Extra API call on every issuance (slower)
- ❌ Doesn't update Invoice Ninja client record
- ❌ Sequential API calls (lookup + issuance)
- ❌ No user visibility into what name was used

**Estimated Time:** 30 minutes

---

## 📊 Comparison Matrix

| Criteria | Option 1: Client Form | Option 2: Modal Button | Option 3: Auto-lookup |
|----------|----------------------|------------------------|----------------------|
| **User Experience** | ⭐⭐⭐⭐⭐ Best | ⭐⭐⭐⭐ Good | ⭐⭐⭐⭐⭐ Best |
| **Performance** | ⭐⭐⭐⭐⭐ Fast (cached) | ⭐⭐⭐ Medium | ⭐⭐ Slow (2 API calls) |
| **Data Quality** | ⭐⭐⭐⭐⭐ Permanent | ⭐⭐ Temporary | ⭐⭐ Temporary |
| **Implementation Time** | 2-3 hours | 1 hour | 30 minutes |
| **Complexity** | High | Medium | Low |
| **Maintenance** | Medium | Low | Low |
| **Offline Support** | ✅ Yes | ❌ No | ❌ No |
| **User Control** | ✅ Can override | ✅ Can see | ❌ Automatic |

---

## 🎯 Recommended Approach

### Short Term (This Week)
1. ✅ **Phase 1 is sufficient** for immediate needs
2. ✅ Monitor production for any issues
3. ✅ Collect user feedback on VAT number appearing in receipts

### Medium Term (Next Week)
If users complain about seeing VAT numbers instead of company names:
- Implement **Option 3** (30 minutes) for quick improvement
- OR implement **Option 2** (1 hour) for better user control

### Long Term (Future Sprint)
If you want to improve overall Invoice Ninja data quality:
- Implement **Option 1** (2-3 hours) for permanent solution
- This will benefit all features, not just e-invoices

---

## 🔧 Implementation Guide for Phase 2

### Prerequisites
1. Review Amego API documentation for `/json/ban_query`
2. Review Taiwan Government API at https://eip.fia.gov.tw/OAI/swagger-ui.html
3. Decide which option to implement
4. Allocate development time

### Step-by-Step for Option 3 (Quickest)

#### 1. Create CompanyLookupService.php
```bash
ssh root@128.199.146.209
cd /var/www/invoiceninja
nano app/Services/CompanyLookupService.php
```

```php
<?php

namespace App\Services;

use App\Services\TaiwanEInvoice\TaiwanEInvoiceService;
use Exception;

class CompanyLookupService
{
    protected TaiwanEInvoiceService $einvoiceService;

    public function __construct()
    {
        $this->einvoiceService = new TaiwanEInvoiceService();
    }

    public function lookupByVat(string $vat): ?string
    {
        // Validate VAT format (8 digits)
        if (!preg_match('/^\d{8}$/', $vat)) {
            return null;
        }

        try {
            // Call Amego API
            $response = $this->einvoiceService->makeRequest('/json/ban_query', [
                ['ban' => $vat]
            ]);

            if (isset($response['data'][0]['company_name'])) {
                \Log::info('Company lookup successful', [
                    'vat' => $vat,
                    'name' => $response['data'][0]['company_name']
                ]);
                return $response['data'][0]['company_name'];
            }

        } catch (Exception $e) {
            \Log::warning('Company lookup failed - using VAT fallback', [
                'vat' => $vat,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }
}
```

#### 2. Modify TaiwanEInvoiceService.php
```php
// Add at the top with other use statements
use App\Services\CompanyLookupService;

// Add in issueReceipt() method around line 225:
if ($isB2B) {
    // Try to lookup company name from government database
    $lookupService = new CompanyLookupService();
    $buyerName = $lookupService->lookupByVat($buyerGui);

    // Fallback to VAT number if lookup fails (non-profit, etc.)
    if (empty($buyerName)) {
        $buyerName = $buyerGui;
        \Log::info('Using VAT number as BuyerName (lookup unavailable)', [
            'gui' => $buyerGui
        ]);
    }
} else {
    $buyerName = '客人';
}

// Later in request data (line 226):
'BuyerName' => $buyerName,
```

#### 3. Test
```bash
# Clear caches
php artisan config:clear
php artisan cache:clear

# Test with a real VAT number
# Check logs for lookup results
tail -f storage/logs/laravel.log
```

---

## 📝 Testing Plan

### Test Cases

#### Test 1: B2C Invoice (No VAT)
- **Input:** No VAT number
- **Expected BuyerName:** `'客人'`
- **Expected BuyerIdentifier:** `'0000000000'`
- **Result:** [ ] Pass / [ ] Fail

#### Test 2: B2B Invoice (Valid For-Profit Company)
- **Input:** VAT = `83464574` (Baba Kevin's BBQ)
- **Expected Behavior:**
  - Phase 1: BuyerName = `'83464574'`
  - Phase 2 (Option 3): BuyerName = `'Baba Kevin's American Barbecue Co., Ltd.'`
- **Result:** [ ] Pass / [ ] Fail

#### Test 3: B2B Invoice (Non-Profit Organization)
- **Input:** VAT = Non-profit VAT number
- **Expected Behavior:**
  - Amego lookup fails (non-profit not supported)
  - Falls back to VAT number
  - Invoice still issues successfully
- **Result:** [ ] Pass / [ ] Fail

#### Test 4: B2B Invoice (Invalid VAT)
- **Input:** VAT = `12345678` (fake)
- **Expected Behavior:**
  - Lookup fails (invalid)
  - Falls back to VAT number
  - Amego may reject invoice
- **Result:** [ ] Pass / [ ] Fail

---

## 🔍 Monitoring & Logging

### Key Metrics to Track
- Company lookup success rate
- Lookup failures by reason (non-profit, invalid, timeout)
- Average lookup time
- E-invoice issuance success rate before/after Phase 2

### Log Messages to Monitor
```
"Company lookup successful" → Good
"Company lookup failed - using VAT fallback" → Expected for non-profits
"Using VAT number as BuyerName (lookup unavailable)" → Normal fallback
```

---

## 🚨 Rollback Plan

### If Phase 2 Causes Issues
```bash
# SSH to production
ssh root@128.199.146.209

# Restore backup (created before Phase 1)
cd /var/www/invoiceninja
cp app/Services/TaiwanEInvoice/TaiwanEInvoiceService.php.backup-* \
   app/Services/TaiwanEInvoice/TaiwanEInvoiceService.php

# Clear caches
php artisan config:clear
php artisan cache:clear
```

---

## 📚 References

### API Documentation
- **Amego API:** Contact Amego for full documentation
- **Taiwan Government API:** https://eip.fia.gov.tw/OAI/swagger-ui.html
- **VAT Validation Logic:** 營利事業統一編號檢查碼邏輯修正說明

### Related Files
- `app/Services/TaiwanEInvoice/TaiwanEInvoiceService.php`
- `app/Http/Controllers/TaiwanReceiptController.php`
- `invoiceninja-ui/src/pages/payments/common/components/IssueTaiwanReceiptModal.tsx`
- `invoiceninja-ui/src/pages/payments/common/hooks/useTaiwanEInvoice.tsx`

### Amego Contact Messages (WeChat)
```
請問您說的抬頭, 是買方, 還是賣方呢

這個截圖, 是買方公司抬頭, 你要自行帶入,
若不知公司抬頭, 在左側有一支 api 可以查詢公司名稱
非營利事業單位, 會查不到抬頭, 可以人工補一下, 或是帶入統編

若您說的抬頭是賣方, 我們系統會自動以貴公司的抬頭帶入
或是, 您可以提供一下, 你用 API 開立發票, 是那一張發票,
提供發票號碼, 我反饋給工程師, 請工程師協助確認問題, 這樣比較快
```

---

## ✅ Next Actions

### Immediate (Before Dinner)
- [x] Deploy Phase 1 fix to production
- [x] Create this documentation file
- [ ] Test Phase 1 with real B2B invoice

### This Week
- [ ] Decide which Phase 2 option to implement
- [ ] Review Taiwan Government API documentation
- [ ] Implement chosen Phase 2 option
- [ ] Test thoroughly on production
- [ ] Update this document with results

### Future Considerations
- [ ] Add caching for company name lookups (30-day TTL)
- [ ] Implement retry logic for API failures
- [ ] Add admin UI to view lookup statistics
- [ ] Consider batch lookup for multiple clients

---

**Document Version:** 1.0
**Last Updated:** 2025-10-27
**Next Review:** After Phase 1 testing complete
