# Taiwan E-Invoice Multi-Company Support

**Date Implemented:** 2025-12-09
**Status:** Complete

---

## Overview

This feature allows issuing Taiwan e-invoices from two different companies:

| Company | Short Code | GUI (統一編號) | Use Case |
|---------|------------|---------------|----------|
| 犇火燻寶有限公司 | `benfire` | 53523822 | TapPay payments (automatic) |
| 霸美燻王有限公司 | `bameixin` | 83464574 | Manual payments (user choice) |

---

## Business Rules

1. **TapPay (Credit Card) Payments:**
   - Always use 犇火燻寶有限公司
   - No company selection shown in UI
   - Automatic selection enforced by backend

2. **Manual Payments:**
   - User can choose which company issues the e-invoice
   - Radio buttons shown in the Issue Taiwan E-Invoice modal
   - Default selection: 犇火燻寶有限公司

3. **Voiding E-Invoices:**
   - Must use the same company credentials that issued the original invoice
   - Company is stored in `payment.custom_value4`
   - Backend automatically reads and uses correct credentials

---

## Data Storage

### Payment Custom Fields

| Field | Purpose | Example Value |
|-------|---------|---------------|
| `custom_value1` | Receipt number | `VC44749500` |
| `custom_value2` | Issue date/time | `2025-12-09 10:30:00` |
| `custom_value3` | Status | `issued` or `voided` |
| `custom_value4` | Issuing company name | `犇火燻寶有限公司` |

### Note on custom_value4

- **New format (2025-12-09+):** Stores full Chinese company name
- **Legacy format (before 2025-12-09):** May contain short codes like `benfire`
- Backend supports both formats for backward compatibility

---

## Environment Variables

Add to `.env` on production server:

```env
# Taiwan E-Invoice Multi-Company Support
# Company 1: 犇火燻寶有限公司 (benfire) - Default, used for TapPay
TAIWAN_EINVOICE_BENFIRE_GUI=53523822
TAIWAN_EINVOICE_BENFIRE_APP_KEY=l4DxRpesVJjbNtTgbdlA

# Company 2: 霸美燻王有限公司 (bameixin) - Available for manual payments
TAIWAN_EINVOICE_BAMEIXIN_GUI=83464574
TAIWAN_EINVOICE_BAMEIXIN_APP_KEY=wx3tVdGzIs4NhCcvSgoG
```

---

## Files Modified

### Backend (invoiceninja-fork)

1. **`app/Services/TaiwanEInvoice/TaiwanEInvoiceService.php`**
   - Added `COMPANIES` constant with both company configs
   - Constructor accepts `$companyCode` parameter
   - Added `setCompanyCredentials()` for credential lookup
   - Added `getCompanyCodeFromName()` static method
   - Added `isValidCompanyCode()` and `isValidCompanyName()` helpers
   - `issueReceipt()` stores company name in `custom_value4`

2. **`app/Http/Controllers/TaiwanReceiptController.php`**
   - Added `isTapPayPayment()` method
   - Added `determineIssuingCompany()` method
   - `issue()` accepts `issuing_company` parameter
   - `void()` reads company from `custom_value4` (supports both name and code)
   - Added `getPaymentType()` endpoint

3. **`routes/api.php`**
   - Added: `GET /api/v1/payments/{payment}/taiwan_receipt/payment_type`

### Frontend (invoiceninja-ui)

4. **`src/pages/payments/common/hooks/useTaiwanEInvoice.tsx`**
   - Added `TAIWAN_EINVOICE_COMPANIES` constant
   - Added `IssuingCompanyCode` type
   - Added `isManualPayment()` helper
   - Updated `issueReceipt()` to accept `issuingCompany` parameter

5. **`src/pages/payments/common/components/IssueTaiwanReceiptModal.tsx`**
   - Added `issuingCompany` state
   - Added radio buttons for company selection (manual payments only)
   - Hidden for TapPay payments

---

## API Endpoints

### Issue E-Invoice
```
POST /api/v1/payments/{payment}/taiwan_receipt
```

**Parameters:**
- `einvoice_email` (optional): Email to send e-invoice
- `buyer_gui` (optional): 8-digit VAT number for B2B
- `issuing_company` (optional): `benfire` or `bameixin` (manual payments only)

**Response:**
```json
{
  "success": true,
  "receipt_number": "VC44749500",
  "message": "E-Invoice issued successfully",
  "issuing_company": "benfire",
  "issuing_company_name": "犇火燻寶有限公司"
}
```

### Void E-Invoice
```
DELETE /api/v1/payments/{payment}/taiwan_receipt
```

**Parameters:**
- `reason` (required): Reason for voiding

**Response:**
```json
{
  "success": true,
  "message": "E-Invoice voided successfully",
  "voided_by_company": "benfire",
  "voided_by_company_name": "犇火燻寶有限公司"
}
```

### Get Payment Type
```
GET /api/v1/payments/{payment}/taiwan_receipt/payment_type
```

**Response:**
```json
{
  "is_tappay": false,
  "is_manual": true,
  "transaction_reference": "Manual entry",
  "allow_company_selection": true
}
```

---

## Detection Logic

### TapPay vs Manual Payment

A payment is considered **TapPay** if:
- `transaction_reference` is NOT empty AND
- `transaction_reference` is NOT "Manual entry"

TapPay transaction references look like: `D20251209JwzCej`

A payment is considered **Manual** if:
- `transaction_reference` is empty OR
- `transaction_reference` is exactly "Manual entry"

---

## Testing Checklist

- [ ] Issue e-invoice for TapPay payment (should auto-use 犇火燻寶)
- [ ] Issue e-invoice for manual payment with default company
- [ ] Issue e-invoice for manual payment selecting 霸美燻王有限公司
- [ ] Verify custom_value4 shows correct Chinese company name
- [ ] Void an e-invoice issued by 犇火燻寶有限公司
- [ ] Void an e-invoice issued by 霸美燻王有限公司
- [ ] Check legacy invoices (with `benfire` code) can still be voided

---

## Troubleshooting

### "Missing API key for company" Error
- Check `.env` has both `TAIWAN_EINVOICE_BENFIRE_APP_KEY` and `TAIWAN_EINVOICE_BAMEIXIN_APP_KEY`
- Run `php artisan config:clear` after updating `.env`

### Wrong Company Used for Voiding
- Check `payment.custom_value4` contains correct company name
- Legacy records may have short codes (`benfire`) - backend handles both

### Radio Buttons Not Showing
- Verify payment is a manual entry (not TapPay)
- Check `transaction_reference` value in database

---

## Related Documentation

- `TAIWAN_EINVOICE_RECOVERY.md` - Initial e-invoice implementation
- `TAIWAN_EINVOICE_BUYERNAME_ENHANCEMENT.md` - BuyerName field handling

---

**Last Updated:** 2025-12-09
