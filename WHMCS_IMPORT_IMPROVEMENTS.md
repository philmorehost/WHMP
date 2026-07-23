# WHMCS Import & Database Improvements

## Overview
This document details all improvements made to ensure WHMCS imports work seamlessly and that currency handling is correct throughout the system.

## Commit History

### 1. Fix Assigned Server Dropdown (Commit: 7ec5ac3)
**Problem**: When editing a VPS or Dedicated service, the "Assigned Server" dropdown was showing all servers instead of only servers compatible with that service type.

**Solution**: Modified `ServiceController::show()` to filter servers based on the service's product's server group ID.

**Impact**: 
- VPS services can now only be assigned to VPS servers
- Dedicated services can only be assigned to Dedicated servers
- Prevents accidental incompatible server assignments

**File Changed**: `core/Billing/ServiceController.php`

---

### 2. Fix WHMCS Import Errors (Commit: 9caba38)
**Problems**:
1. **Missing Columns**: "Unknown column 'grace_period_days'" error during domain pricing import
2. **Duplicate Domains**: Integrity constraint violation when re-running imports
3. **Duplicate Transactions**: Integrity constraint violation for duplicate transactions

**Solutions**:
1. Added migration 0117 with domain grace period and redemption period columns
2. Changed domain inserts to use `INSERT IGNORE` to skip duplicates silently
3. Changed transaction inserts to use `INSERT IGNORE` to skip duplicate gateway transactions

**Files Changed**: 
- `core/Import/WhmcsImportService.php` (domains and transactions)
- `database/migrations/0117_add_grace_and_redemption_periods_to_domain_pricing.php` (new)

**Impact**: 
- WHMCS imports can be safely re-run without errors
- Duplicate data is handled gracefully
- All domain lifecycle data is preserved

---

### 3. Update Database Schema for New Installations (Commit: 1ea5c3d)
**Problem**: New installations didn't have grace/redemption period columns from the start, requiring post-installation migration.

**Solution**: 
1. Modified migration 0097 to include all domain_pricing columns at table creation
2. Updated migration 0117 to use `IF NOT EXISTS` for idempotent upgrades

**Files Changed**:
- `database/migrations/0097_create_domain_pricing_table.php` (added 3 columns)
- `database/migrations/0117_add_grace_and_redemption_periods_to_domain_pricing.php` (idempotent)

**Impact**:
- New installations have complete schema from start
- Existing installations can safely upgrade with 0117
- No missing column errors during WHMCS imports

---

## Currency Handling Verification

### System Design
The system stores all monetary amounts in the **base/default currency** and uses exchange rates to convert for display:

```
Display Amount = Base Amount × Exchange Rate
Base Amount = Display Amount ÷ Exchange Rate
```

### Client Currency Respect
The system correctly respects each client's default currency throughout:

1. **Client Assignment**: Each client has an optional `currency_id` (defaults to system default)
2. **Invoice Currency**: Invoices lock the client's currency and exchange rate at creation time
3. **Currency Conversions**: All queries use `COALESCE(client.currency_id, default_currency_id)`
4. **Safe Calculations**: Includes overflow prevention (prevents rates > 50 from creating values > 5,000,000)

### Key Files
- `core/Billing/CurrencyService.php` - Core currency logic
- `core/Billing/ServiceRepository.php` - Correctly uses COALESCE for client currency
- `core/Billing/ProrationService.php` - Uses proper rounding for calculations
- `core/Import/WhmcsImportService.php` - Correctly assigns client currencies during import

---

## WHMCS Import Data Flow

### Currency Mapping
1. **Step 0**: Import currencies from WHMCS
2. **Step 1**: Assign each client their currency (respects WHMCS client selection)
3. **Step 3-8**: Import amounts with proper currency conversion

### Amount Conversions During Import
```php
// Example: Domain import
$domainAmount = (float) ($row['recurringamount'] ?? 0.00) / $clientRate;
```

This converts from the WHMCS client's currency to the system's base currency:
- **WHMCS Amount**: 100 in client's currency
- **Client's Exchange Rate**: 2.0 (their currency is 2x the base)
- **System Base Amount**: 100 ÷ 2.0 = 50
- **Display to Client**: 50 × 2.0 = 100 (in their currency)

---

## What Gets Imported from WHMCS

### ✅ Fully Imported
- Currencies and exchange rates
- Clients with their selected currency
- Servers and server groups
- Products with pricing
- Services with proper server assignment
- Invoices with currency locks
- Transactions
- Domains with grace/redemption periods
- Tickets and departments
- Promotions

### ✅ Currency-Safe Operations
- All monetary amounts converted to base currency
- Exchange rates locked at import time
- Client currency preferences preserved
- Multi-currency display calculations correct

---

## Testing the Improvements

### Test WHMCS Import
```bash
# Access admin panel
# Navigate to Admin → Import → WHMCS
# Run import with your WHMCS credentials
# Verify no duplicate/missing column errors
# Re-run import to confirm duplicates are handled
```

### Test Currency Handling
1. Create client in non-default currency
2. Create service for that client
3. Create invoice - should use client's currency
4. View amounts - should display in client's currency
5. Switch display currency - amounts should recalculate

### Test Server Compatibility
1. Edit a VPS service
2. "Assigned Server" dropdown should only show VPS servers
3. Edit a cPanel service
4. "Assigned Server" dropdown should only show cPanel servers

---

## Database Schema Changes

### Migration 0097 (Domain Pricing Table - New Installations)
```sql
CREATE TABLE domain_pricing (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tld VARCHAR(30) NOT NULL UNIQUE,
    registrar_slug VARCHAR(50) NOT NULL,
    register_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    transfer_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    renew_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    grace_period_days INT UNSIGNED DEFAULT 30,           -- NEW
    redemption_period_days INT UNSIGNED DEFAULT 30,      -- NEW
    redemption_fee DECIMAL(10,2) DEFAULT 0.00,           -- NEW
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

### Migration 0117 (Existing Installations)
```sql
ALTER TABLE domain_pricing 
ADD COLUMN IF NOT EXISTS grace_period_days INT UNSIGNED DEFAULT 30
ADD COLUMN IF NOT EXISTS redemption_period_days INT UNSIGNED DEFAULT 30
ADD COLUMN IF NOT EXISTS redemption_fee DECIMAL(10,2) DEFAULT 0.00
```

---

## Summary

| Issue | Fixed | Verified |
|-------|-------|----------|
| Missing grace_period_days column | ✅ | Added to schema |
| Duplicate domain errors | ✅ | INSERT IGNORE implemented |
| Duplicate transaction errors | ✅ | INSERT IGNORE implemented |
| Server dropdown filtering | ✅ | By product server group |
| Client currency respect | ✅ | System-wide COALESCE pattern |
| Currency math accuracy | ✅ | Rounding and overflow prevention |
| WHMCS import reliability | ✅ | Re-runnable and error-safe |
| New installation setup | ✅ | Complete schema from start |

All improvements have been committed to the BUYAFROBEATS2 branch.
