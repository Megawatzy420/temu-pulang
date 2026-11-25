# Temu Pulang — Development Log

---

## Phase 1: Database Foundation

**Date:** 2026-11-13
**Status:** ✅ COMPLETED

### Summary
Built the complete database foundation for Temu Pulang.

### Deliverables
- Migrations: `users`, `roles`, `permissions`, `role_user`, `permission_role`, `activity_logs`, `shop_settings`, `application_settings`, `categories`, `category_translations`, `products`, `product_translations`, `product_variants`, `variant_translations`, `modifier_groups`, `modifier_group_translations`, `modifiers`, `modifier_translations`, `product_modifier_groups`.
- Models: `User`, `Role`, `Permission`, `ActivityLog`, `ShopSetting`, `ApplicationSetting`, `Category`, `CategoryTranslation`, `Product`, `ProductTranslation`, `ProductVariant`, `VariantTranslation`, `ModifierGroup`, `ModifierGroupTranslation`, `Modifier`, `ModifierTranslation`, `ProductModifierGroup`.
- Seeders: `RoleAndPermissionSeeder`, `UserSeeder`, `ShopSettingSeeder`, `ApplicationSettingSeeder`, `CategorySeeder`, `ProductSeeder`.
- Tests: 30 feature tests, all passing.
- Docs: `DATABASE_DESIGN.md`, `ARCHITECTURE.md`, `PROJECT_PLAN.md`.

### Test Results
- 30 tests, 30 passed — all green.

---

## Phase 2: Authentication & Frontend Foundation

**Date:** 2026-11-13
**Status:** ✅ COMPLETED

### Summary
Built the complete authentication system, role-based access control, bilingual UI, reusable component library, and initial Owner Dashboard + Cashier POS Livewire components.

### Files Created
| File | Description |
|---|---|
| `app/Http/Middleware/SetLocale.php` | Sets app locale from session on every request |
| `app/Http/Middleware/EnsureUserHasRole.php` | RBAC middleware; protects owner routes |
| `app/Http/Controllers/AuthController.php` | Login, logout, locale switcher |
| `app/Livewire/Dashboard/OwnerDashboard.php` | Owner dashboard Livewire component |
| `app/Livewire/Pos/CashierPos.php` | Full POS terminal Livewire component |
| `lang/id/messages.php` | Full Indonesian translation strings |
| `lang/en/messages.php` | Full English translation strings |
| `resources/views/components/layouts/app.blade.php` | Main app layout (sidebar + header) |
| `resources/views/components/layouts/guest.blade.php` | Guest/auth layout |
| `resources/views/components/button.blade.php` | Reusable button component |
| `resources/views/components/input.blade.php` | Reusable input component |
| `resources/views/components/card.blade.php` | Reusable card component |
| `resources/views/components/badge.blade.php` | Reusable badge component |
| `resources/views/components/modal.blade.php` | Alpine.js modal component |
| `resources/views/components/notification.blade.php` | Toast notification component |
| `resources/views/components/table.blade.php` | Reusable table component |
| `resources/views/auth/login.blade.php` | Branded login page |
| `resources/views/livewire/dashboard/owner-dashboard.blade.php` | Owner dashboard view |
| `resources/views/livewire/pos/cashier-pos.blade.php` | POS terminal view |
| `tests/Feature/AuthenticationAndFrontendTest.php` | 12 auth & frontend tests |

### Files Modified
| File | Change |
|---|---|
| `bootstrap/app.php` | Registered `SetLocale` and `EnsureUserHasRole` middleware |
| `routes/web.php` | Full routing: login, logout, locale, dashboard, pos |
| `app/Models/User.php` | Added roles(), hasRole(), assignRole(), hasPermission(), isOwner(), isCashier(), SoftDeletes |
| `tests/Feature/ExampleTest.php` | Fixed for `/` redirect behaviour |
| `resources/css/app.css` | Added custom Tailwind theme tokens (cream, coffee, caramel palette) |

### Design System
- Warm cream background (`#FAF7F2`)
- Coffee-brown primary (`#2C1810`)
- Caramel accent (`#C8822E`)
- Rounded cards, subtle borders, modern typography
- Responsive desktop/tablet/mobile

### Test Results
- **42 tests, 42 passed, 165 assertions** — all green.
- Pint: clean, no formatting issues.

### Screenshots
| Page | File |
|---|---|
| Login page (ID locale) | `docs/screenshots/login.png` |
| Owner dashboard | `docs/screenshots/owner-dashboard.png` |
| POS terminal (Owner) | `docs/screenshots/pos-owner.png` |
| POS terminal (Cashier) | `docs/screenshots/pos-cashier.png` |

---

## Phase 3: Operational Foundation

**Date:** 2026-11-13
**Status:** ✅ COMPLETED

### Summary
Built the four operational modules for Temu Pulang: Cashier Shift Management lifecycle with pessimistic locking, Cash Movement records ledger with audit reversal support, Shop Settings administration UI for the Owner, and Activity Log audit viewer. Corrected business requirements across the codebase to ensure 0% default tax, 0% default service charge, USB thermal printer connection, and removal of table number assignments from the POS interface.

### Deliverables & Key Features
1. **Shift Management (`ShiftService`, `ShiftManager`)**:
   - Complete lifecycle: `OPEN -> CLOSED` with zero reopen capability.
   - Enforced single active shift per branch via `lockForUpdate()` and database transactions.
   - Active shift dashboard with 4 real-time KPI cards: Opening Cash, Inflows, Outflows, and Expected Cash.
   - Shift closing screen with physical cash drawer count and automated discrepancy calculation (`discrepancy = actual_cash - expected_cash`).
   - Mandatory closing notes when discrepancies exist.
   - Non-cash payment methods architecturally prepared to bypass cash drawer tally.
2. **Cash Movement Ledger (`CashMovementService`)**:
   - Records non-order cash drawer inflows and outflows (`in` / `out`) tied strictly to the active shift.
   - Immutable records with user attribution, timestamp, category, and reason.
   - Reversal workflow (`reverseMovement`) linking original and offsetting audit entries without silent edits.
3. **Shop Settings (`ShopSettingsManager`)**:
   - Owner-only tabbed interface (`role:owner`).
   - 5 configuration tabs: Profil Usaha, Jam Operasional (over-midnight schedules supported), Pajak & Finansial (default 0% tax & 0% service charge), Printer Thermal USB (58mm/80mm, USB connection, display name), and Template Struk.
   - Business day start time configurable (e.g. 04:00) using `Asia/Jakarta` timezone.
4. **Activity Log Viewer (`ActivityLogIndex`)**:
   - Owner-only immutable audit trail (`role:owner`).
   - Search by action/description, filter by user, module (`auth`, `shifts`, `cash_movements`, `settings`, `catalog`), and date range.
   - JSON payload inspector modal showing before/after state and client IP address.
5. **POS & System Corrections**:
   - Removed table assignment inputs and references from POS; preserved clean Dine-in / Takeaway toggle.
   - Updated POS cart tax calculation to pull dynamically from `ShopSetting::current()->tax_rate` (default 0% = Rp 0).
   - Dynamic shift status indicator pill in application header.

### Files Created
| File | Description |
|---|---|
| `database/migrations/2026_11_13_215142_create_shifts_table.php` | Shifts lifecycle and discrepancy tracking migration |
| `database/migrations/2026_11_13_215153_create_cash_movements_table.php` | Cash movements ledger migration with reversal link |
| `database/migrations/2026_11_13_215154_update_shop_settings_for_usb_printer_and_zero_tax.php` | Updated shop settings defaults (0% tax, 0% service charge, USB printer) |
| `database/migrations/2026_11_13_215155_add_module_to_activity_logs_table.php` | Added `module` column to activity_logs with historical backfill |
| `database/migrations/2026_11_13_222918_fix_printer_port_nullable_in_shop_settings.php` | Made printer_port nullable using DB-driver aware migration |
| `database/migrations/2026_11_13_225913_add_strict_concurrency_and_reversal_constraints_to_shifts_and_cash_movements.php` | Added physical database unique constraints for single active shift per branch and single movement reversal |
| `app/Models/Shift.php` | Shift Eloquent model with scopes, cash calculation helpers, immutability hooks, and relations |
| `app/Models/CashMovement.php` | CashMovement Eloquent model with reversal relations, immutability hooks, and type helpers |
| `database/factories/ShiftFactory.php` | Factory for shifts with `closed()` state |
| `database/factories/CashMovementFactory.php` | Factory for cash movements with `out()` state |
| `app/Services/ShiftService.php` | Shift business logic service (open, close, expected cash) with concurrent serialization |
| `app/Services/CashMovementService.php` | Cash movement business logic service (record, reverse) with double-reversal prevention |
| `app/Livewire/Shifts/ShiftManager.php` | Livewire component for shift operations and cash movements |
| `app/Livewire/Settings/ShopSettingsManager.php` | Livewire component for Owner-only shop settings management |
| `app/Livewire/ActivityLogs/ActivityLogIndex.php` | Livewire component for Owner-only activity log inspection |
| `resources/views/livewire/shifts/shift-manager.blade.php` | Shift management view with 4 KPI cards and 4 interactive modals |
| `resources/views/livewire/settings/shop-settings-manager.blade.php` | 5-tab settings management view |
| `resources/views/livewire/activity-logs/activity-log-index.blade.php` | Activity logs audit view with search/filters and JSON modal |
| `tests/Feature/ShiftManagementOperationalTest.php` | 15 feature tests for shift lifecycle and concurrency constraints |
| `tests/Feature/CashMovementOperationalTest.php` | 9 feature tests for cash movement ledger, reversal uniqueness, and immutability |
| `tests/Feature/ShopSettingsOperationalTest.php` | 7 feature tests for shop settings & authorization |
| `tests/Feature/ActivityLogOperationalTest.php` | 4 feature tests for activity log audit trail |

### Files Modified
| File | Change |
|---|---|
| `app/Models/User.php` | Added `shifts()` and `cashMovements()` relationships |
| `app/Models/ShopSetting.php` | Added printer attributes to fillable and casts, percentage accessors |
| `app/Models/ActivityLog.php` | Added `module` to fillable and updated `record()` method to infer module |
| `app/Http/Middleware/EnsureUserHasRole.php` | Added audit logging for unauthorized access attempts |
| `app/Livewire/Pos/CashierPos.php` | Removed table assignment; dynamic 0% tax rate from ShopSetting |
| `resources/views/livewire/pos/cashier-pos.blade.php` | Removed table numbers; dynamic 0% tax display |
| `resources/views/components/layouts/app.blade.php` | Added shift/settings/audit links in sidebar; active shift pill in header |
| `resources/views/components/input.blade.php` | Support explicit `id` attribute for input fields |
| `resources/views/auth/login.blade.php` | Robust demo login helpers for automated browser scripts |
| `routes/web.php` | Added `/shifts`, `/settings`, and `/activity-logs` routes |
| `database/seeders/ShopSettingSeeder.php` | Updated default seed data: 0% tax, 0% service charge, USB printer |
| `lang/id/messages.php` & `lang/en/messages.php` | Removed hardcoded 10% from tax labels |

### Test Results
- **77 tests, 77 passed, 280 assertions** (100% pass rate on isolated test database).
- `php vendor/bin/pint --format agent`: 100% clean formatting.
- `npm run build`: Production bundle compiled cleanly in 452ms.

### Screenshots Captured
| Screen | Path | Description |
|---|---|---|
| Dashboard | `docs/screenshots/phase3-dashboard.png` | Owner dashboard with active shift pill |
| POS Terminal | `docs/screenshots/phase3-pos.png` | Cashier POS with 0% tax & no table selection |
| Active Shift | `docs/screenshots/phase3-shifts-active.png` | Active shift with 4 KPI cards and movements |
| Cash In Modal | `docs/screenshots/phase3-shifts-modal-in.png` | Cash In modal opened with category & amount |
| Shift Standby | `docs/screenshots/phase3-shifts.png` | Shift page before opening a shift |
| Settings (Profil) | `docs/screenshots/phase3-settings-profile.png` | Shop Settings: Business profile tab |
| Settings (Jam) | `docs/screenshots/phase3-settings-hours.png` | Shop Settings: Operating hours & business day |
| Settings (Pajak) | `docs/screenshots/phase3-settings-finance.png` | Shop Settings: Finance tab (0% tax & service) |
| Settings (Printer) | `docs/screenshots/phase3-settings-printer.png` | Shop Settings: USB Thermal printer tab |
| Activity Logs | `docs/screenshots/phase3-activity-logs.png` | Owner activity logs audit viewer |

### Known Limitations & Phase 4 Transition
- No real orders or transactions are processed in Phase 3.
- Expected cash calculations intentionally omit orders/sales until Phase 4 (Order Engine) and Phase 5 (Payments).
- Direct ESC/POS raw USB communication will be built in Phase 5.

---

## Phase 4: Menu Management & Order Engine

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Built the complete menu management backoffice (Category CRUD, Modifier Groups & nested options repeater, Product CRUD with variants and image uploads, and real-time sold out toggles) and the transactional Order Engine (concurrency-safe business-day call numbers `TP-001`, frozen line-item and modifier snapshots, lifecycle transition state machine, and kitchen queue board with live polling).

### Files Created
| File | Description |
|---|---|
| `database/migrations/2026_11_13_232543_create_orders_and_order_items_tables.php` | Additive schema for orders, frozen order items, modifiers, and audit histories |
| `app/Models/Order.php` | Order model with ULID, sequence, financial immutability guard, and status helpers |
| `app/Models/OrderItem.php` | Frozen line-item snapshot with localized accessors and relationships |
| `app/Models/OrderItemModifier.php` | Frozen modifier options snapshot with localized accessors |
| `app/Models/OrderStatusHistory.php` | Append-only status transition audit trail |
| `app/Services/OrderService.php` | Core transactional domain engine: business-day sequence generation, order creation, lifecycle progression, and Owner cancellation |
| `app/Livewire/Menu/CategoryManager.php` | Category CRUD, bilingual names/descriptions, sort order, active toggle |
| `resources/views/livewire/menu/category-manager.blade.php` | Category management view with modal dialogs |
| `app/Livewire/Menu/ModifierManager.php` | Modifier groups & nested modifier options repeater with IDR prices |
| `resources/views/livewire/menu/modifier-manager.blade.php` | Modifier groups management view with repeater rows |
| `app/Livewire/Menu/ProductIndex.php` | Product catalog index with search, category pills, and instant sold-out toggle |
| `resources/views/livewire/menu/product-index.blade.php` | Product catalog table view |
| `app/Livewire/Menu/ProductForm.php` | 4-tab product create/edit form with image upload, variants repeater, and modifier assignment |
| `resources/views/livewire/menu/product-form.blade.php` | 4-tab product form view |
| `app/Livewire/Orders/OrderIndex.php` | Real-time kitchen queue & order management board |
| `resources/views/livewire/orders/order-index.blade.php` | Kitchen queue board view with 10s polling, status cards, and action buttons |
| `database/factories/OrderFactory.php` | Test factory for orders with lifecycle states |
| `database/factories/OrderItemFactory.php` | Test factory for frozen order items |
| `tests/Feature/OrderModelAndSchemaTest.php` | Schema integrity, cascade force-delete, product soft-delete snapshot preservation, and immutability tests |
| `tests/Feature/CategoryAndMenuManagementTest.php` | Category, modifier group, product form, and sold-out toggle tests |
| `tests/Feature/OrderEngineAndLifecycleTest.php` | Order intake, idempotency, shift checks, lifecycle progression, and Owner-only cancellation tests |
| `tests/Feature/OrderConcurrencyAndNumberingTest.php` | Concurrency serial lock, daily sequence `TP-001`, rollover at 06:00, and tax calculations |

### Files Modified
| File | Change |
|---|---|
| `app/Models/Shift.php` | Added `orders(): HasMany` relationship |
| `app/Models/User.php` | Added `orders(): HasMany` relationship |
| `app/Models/Modifier.php` | Added `group(): BelongsTo` relationship alias |
| `app/Livewire/Pos/CashierPos.php` | Integrated with `OrderService`, active shift validation, and custom customer name input |
| `resources/views/livewire/pos/cashier-pos.blade.php` | Added customer name/notes input, updated checkout modal to trigger real order intake |
| `resources/views/components/layouts/app.blade.php` | Added "Menu & Katalog" links (Daftar Produk, Kategori Menu, Grup Modifier) and "Antrean & Pesanan" link |
| `routes/web.php` | Registered `/menu/*` routes (`role:owner`) and `/orders` route (`role:owner,cashier`) |

### Test Results
- **98 tests, 98 passed, 375 assertions** (100% pass rate on isolated SQLite test database).
- `php vendor/bin/pint --format agent`: 100% clean formatting.
- `npm run build`: Production bundle compiled cleanly.

### Known Limitations & Phase 5 Transition
- Payments remain in `awaiting_payment` / simulated state; real payment flows (Cash change calculation, QRIS, EDC) will be implemented in Phase 5.
- Receipt printing to physical Windows USB ESC/POS thermal printers will be implemented in Phase 5.
- Stock deduction upon order completion is deferred to Phase 6 (Inventory & Purchasing).



---

## Phase 4 Hardening — Order Engine Integrity

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Post-audit hardening of the Phase 4 Order Engine. Addressed all 5 gaps identified in the Phase 4 Final Verification Audit Report:

1. **DB UNIQUE constraints** on `(business_date, daily_sequence)` and `(business_date, daily_order_number)`.
2. **Idempotency key lifecycle** in `CashierPos` — stable key per cart, refreshed only on new order.
3. **Shift TOCTOU close** — active shift re-validated with `lockForUpdate()` inside the DB transaction.
4. **Paid order cancellation protection** — `advanceStatus()` and `cancelOrder()` both block cancellation of paid, queued, preparing, and ready orders.
5. **New hardening test suite** (`OrderHardeningTest`) covering all four gaps.

### Files Created
| File | Description |
|---|---|
| `database/migrations/2026_11_14_000022_add_unique_constraints_to_orders_table.php` | Additive migration adding UNIQUE constraints with pre-flight duplicate check |
| `tests/Feature/OrderHardeningTest.php` | 11 new tests: DB constraint rejection, idempotency key lifecycle, TOCTOU shift close, paid cancellation rejection |

### Files Modified
| File | Change |
|---|---|
| `app/Services/OrderService.php` | Shift re-locked with `lockForUpdate()` inside transaction; `advanceStatus()` removes `cancelled` from paid+ transitions; `cancelOrder()` explicitly blocks paid/queued/preparing/ready orders |
| `app/Livewire/Pos/CashierPos.php` | Added `$checkoutIdempotencyKey` property; `openCheckout()` generates key once per cart; `processSimulatedPayment()` reuses key on retry; key cleared on success/clear-cart |

### Migration Applied (batch 7)
```
2026_11_14_000022_add_unique_constraints_to_orders_table  [7] Ran
```
Pre-flight check confirmed **0 duplicate rows** in production `orders` table before applying constraints.

### Test Results
- **109 tests, 109 passed, 407 assertions** (100% pass rate on isolated SQLite test database)
- `php vendor/bin/pint --test`: ✅ PASSED
- `npm run build`: ✅ Compiled cleanly (Vite 8.3.8)

### Transition Table — `advanceStatus()` (updated)
| From | Allowed To |
|---|---|
| `draft` | `awaiting_payment` |
| `awaiting_payment` | `paid`, `cancelled` |
| `paid` | `queued`, `preparing` *(cancelled removed)* |
| `queued` | `preparing` *(cancelled removed)* |
| `preparing` | `ready` *(cancelled removed)* |
| `ready` | `completed` |
| `completed` | *(terminal)* |
| `cancelled` | *(terminal)* |

### Remaining Risks (Phase 5 scope)
- Paid order cancellation requires a formal refund workflow (Phase 5).
- Idempotency key is session-scoped (Livewire component state); if the server restarts mid-checkout, the client may generate a new key — which is acceptable as the previous order was not committed.


---

## Phase 5: Task 5.1 — Database Foundation

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Implemented the additive, non-destructive database foundation for Phase 5 (Payments, Refunds & Persistent Print Queue).

### Deliverables
- **Additive Migration:**
  - `database/migrations/2026_11_14_003900_create_phase5_payments_refunds_and_print_jobs_tables.php` (batch 8).
- **Database Tables Created:**
  1. `qris_confirmations`: Server-authoritative QRIS confirmations persisted with cashier ID, expected amount, server timestamp, and single-use `consumed_at` tracking.
  2. `payment_transactions`: Parent checkout record enforcing strictly 1 transaction per order via `UNIQUE(order_id)`, composite sequence constraints, and request fingerprint hash.
  3. `payments`: Child tender lines (`cash`, `qris`, `debit`, `credit`, `transfer`) with change calculation, references, and cashier QRIS confirmation link.
  4. `refund_transactions`: Parent refund request authorized by Owner with reason and request fingerprint hash.
  5. `payment_refunds`: Child explicit allocation lines referencing original `payment_id`, tracking original tender vs payout method, and nullable `shift_id` (required only for cash refunds).
  6. `print_jobs`: Persistent queue with claim tokens (`claim_token` UUID), lease timestamps (`locked_at`), attempts counter, `max_attempts` limit, and reprint lineage (`original_print_job_id`).
  7. `orders` table addition: Added indexed `payment_status` column (`unpaid`, `paid`, `partially_refunded`, `refunded`).
- **Eloquent Models Created / Updated:**
  - `PaymentTransaction.php`: Enforces append-only immutability in `booted()` and provides dynamic `refundStatus()` calculation.
  - `Payment.php`: Enforces append-only immutability in `booted()`, protects `order_id` from mass assignment, and provides `remainingRefundableBalance()`.
  - `RefundTransaction.php`: Enforces append-only immutability in `booted()` for both updates and deletes.
  - `PaymentRefund.php`: Enforces append-only immutability in `booted()` for both updates and deletes.
  - `QrisConfirmation.php`: Manages `pending` vs `consumed` lifecycle.
  - `PrintJob.php`: Manages queue status, claim token, and reprint lineage.
  - `Order.php`: Added `payment_status` constants and relations (`paymentTransaction`, `payments`, `refundTransactions`, `paymentRefunds`, `qrisConfirmations`, `printJobs`); protected `payment_status` from mass assignment by omitting it from `$fillable`.
  - `Shift.php`: Added relations (`paymentTransactions`, `payments`, `paymentRefunds`).
- **Post-Review Hardening:**
  - Protected `Order.payment_status` by removing it from `$fillable`; explicit assignment followed by model `save()` preserves standard Eloquent events while preventing mass assignment.
  - Protected `Payment.order_id` by omitting it from `$fillable`; explicit assignment or relation builder satisfies the database non-nullable FK constraint safely.
  - Added delete immutability verification for `RefundTransaction` and `PaymentRefund`.
  - Added uniqueness constraint tests for payment transaction idempotency keys, refund transaction idempotency keys, and daily business-date sequence numbers.
- **Automated Tests:**
  - `tests/Feature/Phase5DatabaseFoundationTest.php`: 12 tests, 36 assertions covering table existence, 1:1 order uniqueness, model immutability guards (update & delete), idempotency uniqueness, sequence uniqueness, QRIS lifecycle, print job claim tokens, and mass assignment protections.
  - Test Suite: **121 tests, 443 assertions — 100% passing**.
  - Laravel Pint: Clean, 100% passing.
  - Vite: Build compiled cleanly.

---

## Phase 5: Task 5.2 — PaymentService & Settlement Engine

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Implemented the backend transactional settlement service `PaymentService` supporting single and split payments, integer Rupiah calculations, atomic QRIS confirmation verification and consumption, multi-tender allocations, request fingerprinting with 409 Conflict rejection, concurrency-safe sequential transaction numbering, and audit trail logging.

### Deliverables
- **Core Service Layer:**
  - `app/Services/PaymentService.php`:
    - `settlePayment(...)`: Single and split payment intake, pessimistic write locks on Shift (`lockForUpdate()`), Order (`lockForUpdate()`), and QRIS confirmations (`lockForUpdate()`).
    - `normalizeAndValidateTenders(...)`: Strict integer Rupiah validation, tender method validation (`cash`, `qris`, `debit`, `credit`, `transfer`), cash change validation, exact non-cash tender validation, exact match against `$order->total_amount`.
    - `createQrisConfirmation(...)`: Server-authoritative generation of QRIS confirmation records with cashier identity, expected integer amount, server timestamp, and single-use `pending` status.
    - `generateRequestFingerprint(...)`: Canonical SHA-256 fingerprint incorporating `order_id`, `shift_id`, `cashier_user_id`, `total_amount`, and sorted normalized tenders (including `qris_confirmation_id`s, tender methods, amounts, change, and references).
    - `getNextDailySequence(...)`: Concurrency-safe sequence numbering serialized by locking active `ShopSetting`. Generates formatted call number `PAY-YYYYMMDD-0001`.
- **Integrations & Safeguards:**
  - Shift validation: Enforces active open shift and prevents unauthorized cashier cross-shift settlement (permitting Owner oversight).
  - QRIS ownership & consumption: Validates that QRIS confirmation belongs to the target order and was confirmed by the authenticated cashier (or Owner), has matching expected amount, and is in `pending` status; transitions atomically to `consumed` with `consumed_at`. Unauthorized cross-cashier processing is strictly rejected.
  - Atomic Order state transition: Atomically sets `payment_status = paid`, `status = paid`, `paid_at`, and creates `OrderStatusHistory`.
  - Idempotency & Conflict: Same key + same canonical fingerprint returns existing transaction; same key with changed shift, changed cashier, or modified tenders throws HTTP 409 Conflict (`ConflictHttpException`).
  - Immutability compliance: Child `Payment` rows explicitly set non-nullable `order_id` avoiding mass assignment.
  - Audit logging: Records `ActivityLog::record(action: 'payment.settled', module: 'payments', ...)`.
  - Concurrency guarantees: Sequential payment numbering is protected at the application level via `ShopSetting` row locking (`lockForUpdate()`) and enforced at the database level via composite UNIQUE constraints (`uniq_pay_trx_biz_date_seq`, `uniq_pay_trx_biz_date_num`). True concurrent multi-process execution remains guarded by these database guarantees.
- **Automated Tests:**
  - `tests/Feature/PaymentServiceSettlementTest.php` (20 tests, 85 assertions):
    1. Single cash payment with change.
    2. Single non-cash payment (debit) with reference number.
    3. Split payment (Cash + QRIS).
    4. Empty tenders rejection.
    5. Incorrect tender total rejection (under-tendered and over-tendered).
    6. Invalid tender types and amounts (floats, negative, zero, invalid strings).
    7. Insufficient cash and incorrect change rejection.
    8. QRIS without confirmation rejection.
    9. QRIS confirmation belonging to another order rejection.
    10. QRIS amount mismatch rejection.
    11. Reusing already-consumed QRIS confirmation rejection.
    12. QRIS confirmation ownership validation: rejects unauthorized cashier consumption; permits Owner authorization exception.
    13. Identical idempotent retry returns same transaction without duplicate rows.
    14. Same idempotency key with different request fingerprint throws HTTP 409 Conflict.
    15. Same idempotency key across different shifts produces fingerprint mismatch and throws HTTP 409 Conflict.
    16. Same idempotency key across different cashiers produces fingerprint mismatch and throws HTTP 409 Conflict.
    17. Duplicate settlement for already paid order rejection (`DomainException`).
    18. Inactive or unauthorized shift rejection (closed shift, cross-cashier shift, owner bypass).
    19. Rollback behavior after settlement failure (reverts QRIS consumption, order remains unpaid).
    20. Payment transaction numbering under sequential settlements (`PAY-YYYYMMDD-0001`, `0002`, `0003`).

### Test Results
- `tests/Feature/PaymentServiceSettlementTest.php`: 20 tests, 85 assertions — 100% passing.
- Complete Test Suite: **141 tests, 528 assertions — 100% passing**.
- Laravel Pint: Clean, 100% passing.
- Vite: Build compiled cleanly.

---

## Phase 5: Task 5.3 — Shift Cash Integration

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Integrated completed cash payments into shift expected-cash balance calculations, handled cash change correctly, added forward-compatible cash refund support, separated non-cash payments from physical cash drawer balances, updated shift closing reconciliation, and expanded audit logging without creating duplicate manual cash movements.

### Deliverables
- **Model Enhancements (`app/Models/Shift.php`):**
  - `totalCashPayments()`: Derives net cash sales directly from immutable `Payment` records (`payment_method = 'cash'`).
  - `totalCashTendered()`: Gross cash handed by customers for this shift.
  - `totalCashChange()`: Total cash returned to customers for this shift.
  - `totalNonCashPayments()`: Sum of non-cash tenders (`qris`, `debit`, `credit`, `transfer`) maintained separately from physical drawer.
  - `totalCashRefunds()`: Forward-compatible subtraction of cash refunds (`PaymentRefund.refund_method = 'cash'`) prepared for Task 5.4.
  - `currentExpectedCash()`: Updated formula:
    `Expected Cash = Opening Cash + Manual Cash In - Manual Cash Out + Net Cash Sales - Cash Refunds`.
  - `cashReconciliationSummary()`: Returns structured array with all 9 reconciliation dimensions.
- **Service Layer Updates (`app/Services/ShiftService.php`):**
  - Updated `calculateExpectedCash(Shift $shift)` to evaluate the comprehensive formula.
  - Added `getCashSummary(Shift $shift)` returning structured reconciliation details.
  - Updated `closeShift(...)` audit log properties to capture `cash_sales`, `cash_tendered`, `cash_change`, `cash_refunds`, `non_cash_sales`, `expected_cash`, `actual_cash`, and `discrepancy`.
- **UI Enhancements (`resources/views/livewire/shifts/shift-manager.blade.php`):**
  - Active shift stat cards: Added "+ Penjualan Tunai", updated expected cash formula descriptor, and added informative banner for Non-Cash Sales (QRIS/EDC) confirming separation from drawer.
  - Close Shift modal breakdown: Displays Net Cash Sales, Manual Inflows, Manual Outflows, Cash Refunds (if any), Expected Cash, and Non-Cash Sales.
- **Automated Tests:**
  - `tests/Feature/ShiftCashIntegrationTest.php` (7 tests, 38 assertions):
    1. Cash-only payments integrate into expected cash.
    2. Cash change correctly affects net cash (`tendered - change = net`).
    3. Non-cash payments (QRIS/Debit) do not affect physical cash drawer.
    4. Split payments only add cash portion to drawer.
    5. Full reconciliation formula combining manual movements, cash sales, and simulated cash refunds.
    6. Cash payments derived from immutable payment records without duplicating `CashMovement` rows.
    7. Shift closing reconciliation records discrepancy and detailed audit log.

### Test Results
- `tests/Feature/ShiftCashIntegrationTest.php`: 7 tests, 38 assertions — 100% passing.
- Complete Test Suite: **148 tests, 566 assertions — 100% passing**.
- Laravel Pint: Clean, 100% passing.
- Vite: Build compiled cleanly.


---

## Phase 5: Task 5.4 — RefundService & Refund Allocation Engine

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Implemented a fully auditable, append-only refund engine supporting partial and full refunds, explicit allocation to original payment records, deterministic request fingerprinting with idempotency, Owner-only authorization, cash/non-cash path separation, atomic order status transitions, and comprehensive audit logging. No existing payment records are ever modified.

### Files Created
| File | Description |
|---|---|
| `app/Services/RefundService.php` | Complete refund service: `processRefund()`, `normalizeAllocations()`, `generateRefundRequestFingerprint()`, `getNextRefundDailySequence()`, `calculateBusinessDate()` |
| `tests/Feature/RefundServiceTest.php` | 49 feature tests covering all required scenarios (see below) |

### Architecture Decisions

**Owner-only authorization:**
- Pre-flight check via `$authorizer->isOwner()` using `User::hasRole('owner')`.
- Cashier callers receive `InvalidArgumentException` immediately — no DB locks acquired.

**Mandatory reason:**
- Empty/whitespace-only `$reason` is rejected with `InvalidArgumentException` before any DB work.

**Canonical request fingerprint (SHA-256):**
- Payload: `order_id`, `authorizer_user_id`, `total_refunded_amount`, sorted allocation array (`payment_id`, `amount`, `refund_method`), `reason`.
- Allocations sorted by `[payment_id, amount, refund_method]` before hashing — array order cannot cause false mismatches.
- Same idempotency key + matching fingerprint → returns existing `RefundTransaction` (no-op).
- Same idempotency key + mismatched fingerprint → `ConflictHttpException` (HTTP 409).

**Locking order (deadlock-safe):**
1. Lock `Shift` row (if any cash allocation) — validates shift is still open.
2. Lock `Order` row — validates payment status is still refundable.
3. Lock `Payment` rows in ascending `id` order — consistent global lock order prevents deadlocks.

**Allocation normalization:**
- `payment_id` must be positive integer.
- `amount` must be positive integer Rupiah.
- `refund_method` must be one of: `cash`, `qris_reversal`, `manual_transfer`.
- Duplicate entries for the same `payment_id` with the **same** refund method are **combined** (summed).
- Duplicate entries for the same `payment_id` with **different** refund methods are **rejected** (`InvalidArgumentException`).

**Balance validation (per-payment, per-lock):**
- `Payment::remainingRefundableBalance()` = `amount - SUM(payment_refunds.amount)`.
- Aggregated allocation for each `payment_id` must not exceed remaining balance.
- Cumulative total across all payments for the order must not exceed `SUM(Payment.amount)`.

**Cash vs. non-cash shift handling:**
- ANY allocation with `refund_method = 'cash'` → requires active, open `Shift` parameter.
- Non-cash allocations (`qris_reversal`, `manual_transfer`) → `shift_id = NULL` on `PaymentRefund`, no shift required.
- Cash refunds automatically reduce `Shift::currentExpectedCash()` via existing `totalCashRefunds()` formula — no duplicate `CashMovement` entries created.

**Daily sequence (refund transactions):**
- Locks active `ShopSetting` row to serialize concurrent counter queries.
- Queries `MAX(daily_sequence)` from `refund_transactions` for the given `business_date`.
- Format: `RFD-YYYYMMDD-0001`.

**Order status transitions:**
- After creating all `PaymentRefund` records, recalculates `SUM(payment_refunds.amount)` for the order.
- If total refunded ≥ total paid → `payment_status = 'refunded'`.
- If total refunded < total paid → `payment_status = 'partially_refunded'`.
- `Order.payment_status` updated via explicit attribute assignment (`$order->payment_status = $val; $order->save()`) — not in `$fillable`.

**Cancellation on full refund:**
- Fully refunded orders in `paid`, `queued`, `preparing`, or `ready` become `cancelled` via direct `DB::table('orders')->update(...)` (bypasses `cancelOrder()` which blocks paid+ statuses) with `cancellation_reason`, `cancelled_by_user_id`, `cancelled_at`.
- Fully refunded orders in `completed` or `cancelled` keep their status (`completed` remains `completed`, `cancelled` remains `cancelled`).
- `OrderStatusHistory` recorded for both order-status and payment-status transitions.

**Immutability preserved:**
- `PaymentTransaction`, `Payment` — `booted()` guard throws `DomainException` on update/delete. Never touched by RefundService.
- `RefundTransaction`, `PaymentRefund` — also immutable after creation.

**Audit logging:**
- `ActivityLog::record(action: 'refund.processed', module: 'payments', ...)` captures: refund_transaction_id/number, order_id/number, authorizer identity, total amount, reason, allocations, shift_id, idempotency_key, request_fingerprint.

### Test Coverage (49 tests, 130 assertions)

| # | Test | Scenario |
|---|---|---|
| 1 | `test_full_cash_refund_succeeds` | Full cash refund — creates RefundTransaction + PaymentRefund, sets `refunded` |
| 2 | `test_partial_cash_refund_updates_payment_status_to_partially_refunded` | Partial refund sets `partially_refunded` and reduces remaining balance |
| 3 | `test_non_cash_refund_succeeds_without_active_shift` | QRIS reversal succeeds without shift; shift_id=null on PaymentRefund |
| 4 | `test_manual_transfer_refund_succeeds` | Manual transfer refund without shift succeeds |
| 5 | `test_multiple_allocation_lines_in_one_refund_transaction` | Split-payment order: cash + transfer refunded in one RefundTransaction |
| 6 | `test_refund_exceeding_remaining_balance_is_rejected` | Amount > remaining balance → `InvalidArgumentException` |
| 7 | `test_cumulative_refunds_cannot_exceed_total_paid` | Second refund exceeding remaining → rejected |
| 8 | `test_duplicate_allocation_entries_for_same_payment_are_combined` | Two lines for same payment_id/method → combined into one PaymentRefund |
| 9 | `test_payment_belonging_to_another_order_is_rejected` | Cross-order payment → rejected |
| 10 | `test_cashier_cannot_process_refund` | Cashier caller → `InvalidArgumentException` ("Hanya Pemilik") |
| 11 | `test_cash_refund_without_active_shift_is_rejected` | Cash refund, shift=null → rejected |
| 12 | `test_non_cash_refund_does_not_require_shift` | Non-cash refund with closed shift → succeeds |
| 13 | `test_identical_idempotent_retry_returns_original_refund_transaction` | Same key + same fingerprint → same RefundTransaction |
| 14 | `test_mismatched_idempotency_key_fingerprint_throws_conflict` | Same key + different amount → `ConflictHttpException` (HTTP 409) |
| 15 | `test_completed_order_remains_completed_after_full_refund` | Completed order → status stays `completed`, payment_status → `refunded` |
| 16 | `test_fully_refunded_non_completed_order_is_cancelled_with_history` | Paid order fully refunded → cancelled with history |
| 17 | `test_unpaid_order_cannot_be_refunded` | Unpaid order → `DomainException` |
| 18 | `test_cash_refund_reduces_expected_cash_in_shift` | Expected cash decreases by refund amount |
| 19 | `test_non_cash_refund_does_not_affect_expected_cash` | QRIS reversal → expected cash unchanged |
| 20 | `test_rollback_when_refund_processing_fails` | Invalid amount → no RefundTransaction created, order status unchanged |
| 21 | `test_audit_log_is_recorded_on_successful_refund` | ActivityLog entry created with correct action/module |
| 22 | `test_zero_amount_allocation_is_rejected` | amount=0 → `InvalidArgumentException` |
| 23 | `test_invalid_refund_method_is_rejected` | Unknown method → `InvalidArgumentException` |
| 24 | `test_empty_reason_is_rejected` | Whitespace reason → `InvalidArgumentException` |
| 25 | `test_partial_refund_does_not_cancel_order` | Partial refund → order status stays `paid` |
| 26 | `test_daily_sequence_increments_for_multiple_refund_transactions` | Sequence 1 then 2; unique transaction numbers |
| 27 | `test_already_fully_refunded_order_is_rejected` | Second refund on fully-refunded order → `DomainException` |
| 28 | `test_same_payment_id_with_different_refund_methods_is_rejected` | Same payment_id, two different methods → rejected |
| 29 | `test_cash_refund_with_closed_shift_is_rejected` | Shift closed before refund → rejected |
| 30 | `test_original_payment_record_is_not_modified_after_refund` | Payment.amount/method/updated_at unchanged after refund |
| 31 | `test_original_payment_method_preserved_on_refund_record` | `original_payment_method` on PaymentRefund matches source Payment |
| 32 | `test_empty_allocations_array_is_rejected` | Empty allocations array → `InvalidArgumentException` |
| 33 | `test_already_cancelled_order_cannot_be_re_cancelled` | Already-cancelled order retains its original cancellation record and reason |
| 34 | `test_idempotent_retry_does_not_create_additional_child_refunds` | Retrying identical request does not create duplicate `PaymentRefund` records |
| 35 | `test_parent_total_refunded_amount_equals_sum_of_child_allocations` | Parent `total_refunded_amount` strictly equals sum of child `PaymentRefund` amounts |
| 36 | `test_unique_constraint_on_idempotency_key_prevents_duplicate_refund_transactions` | Pre-existing/concurrent winner idempotency key safely returns existing record |
| 37 | `test_non_cash_refund_methods_record_intent_only_not_external_completion` | Non-cash refund methods record ledger intent only; no external status claim made |
| 38 | `test_remaining_balance_aggregates_across_all_refund_methods` | Balance tracking aggregates both cash and non-cash refunds cumulatively |
| 39 | `test_duplicate_allocation_lines_and_single_combined_allocation_produce_same_fingerprint` | Canonical allocation combines duplicate lines before fingerprinting (100+200 == 300) |
| 40 | `test_equivalent_allocation_payloads_with_same_idempotency_key_return_original_transaction` | Equivalent payloads with duplicate lines return same transaction on idempotent retry |
| 41 | `test_conflicting_refund_methods_for_same_payment_are_rejected_in_normalization` | Rejects conflicting refund methods for identical payment_id during normalization |
| 42 | `test_exact_sqlite_idempotency_unique_failure_is_recognized_and_recovers_winner` | Simulates SQLite UNIQUE constraint failure specifically on `idempotency_key` and recovers winner |
| 43 | `test_simulated_mysql_idempotency_duplicate_key_failure_is_recognized` | Simulates MySQL 1062 duplicate key on `refund_transactions_idempotency_key_unique` and recovers winner |
| 44 | `test_collision_with_different_fingerprint_throws_conflict` | Collision on unique key with mismatched payload correctly throws HTTP 409 |
| 45 | `test_sqlite_foreign_key_violation_is_not_treated_as_idempotency_collision` | SQLite foreign key constraint violations are not treated as idempotency collisions (re-thrown) |
| 46 | `test_sqlite_not_null_violation_is_not_treated_as_idempotency_collision` | SQLite NOT NULL constraint violations are not treated as idempotency collisions (re-thrown) |
| 47 | `test_sqlite_check_constraint_violation_is_not_treated_as_idempotency_collision` | SQLite CHECK constraint violations are not treated as idempotency collisions (re-thrown) |
| 48 | `test_duplicate_key_error_for_another_unique_index_is_not_treated_as_idempotency_collision` | Unique constraint violations on other indexes (e.g. `ulid`, `uniq_ref_trx_biz_date_seq`) are re-thrown |
| 49 | `test_failed_collision_attempt_leaves_no_extra_records` | Unsuccessful or recovered collision leaves zero orphaned parent or child records |

### Test Results
- `tests/Feature/RefundServiceTest.php`: **49 tests, 130 assertions — 100% passing.**
- Complete Test Suite: **197 tests, 696 assertions — 100% passing.**
- Laravel Pint: Clean, 100% passing.
- Vite: No frontend changes.

### Known Limitations / Out of Scope
- **Concurrent refund attempts:** Deadlock safety achieved via deterministic ascending payment_id lock order. The SQLite test database does not enforce `SELECT ... FOR UPDATE`, so concurrent scenario testing is structural (single-threaded) rather than truly parallel.
- **Receipt printing for refunds:** Deferred to Task 5.5 (PrintService).
- **Inventory restoration / ingredient waste accounting:** Not in scope for Phase 5.
- **UI for refund initiation:** Livewire refund UI not implemented — RefundService is production-ready for integration.

---

## Task 5.5 — Printing Infrastructure & Spooler

**Date:** 2026-11-14
**Status:** ✅ COMPLETED

### Summary
Built the pragmatic v1 printing infrastructure layer for a single Windows PC and single USB thermal printer. Implements raw Win32 spooling via a compiled C# binary helper, pure PHP ESC/POS generation, receipt & kitchen ticket renderers, print lifecycle management, single-instance background worker with crash recovery, test print CLI, and atomic integration with payment settlement.

### Key Deliverables
1. **Additive Database Migration:**
   - `2026_11_14_134310_add_cashier_name_to_payment_transactions.php` (Batch 9): Adds `cashier_name` (`VARCHAR(100)`, nullable) to `payment_transactions`.
2. **Native Win32 Spooler Gateway:**
   - `storage/bin/RawPrinterHelper.cs` → compiled to `storage/bin/tp-rawprint.exe` (7,168 bytes via .NET 4.0 `csc.exe`).
   - P/Invoke calls: `OpenPrinter`, `StartDocPrinter`, `StartPagePrinter`, `WritePrinter` (with partial write loop), `EndPagePrinter`, `EndDocPrinter`, `ClosePrinter`.
   - Precise exit codes: 0 (completed), 1 (args), 2 (file), 3 (OpenPrinter), 4 (StartDoc/Page), 5 (partial write), 6 (EndPage/Doc), 7 (exception), 99 (timeout).
3. **Core Printing Services & CLI Commands:**
   - `app/Services/Printing/EscPosBuilder.php`: 80mm ESC/POS builder (48 cols) with multibyte UTF-8 sanitization (`/u` flag). Cutter and cash drawer disabled per v1 scope.
   - `app/Services/Printing/ReceiptRendererService.php`: Customer receipt renderer with shop branding, cashier fallback chain, frozen tax snapshots, tendered change breakdown, and reprint watermark.
   - `app/Services/Printing/KitchenTicketRendererService.php`: Kitchen/bar ticket renderer displaying items, variants, modifiers, and instructions without financial amounts.
   - `app/Services/PrintService.php`: Lifecycle service managing atomic queueing, reprint queueing with audit logging, safe manual retry (`failed` only), and owner resolution for `uncertain` jobs.
   - `app/Console/Commands/PrintWorkerCommand.php` (`print:work`): Background worker designed for Windows Task Scheduler single-instance enforcement. Features startup blocking crash recovery for stale processing jobs (>60s) and UUID claim-token fencing.
   - `app/Console/Commands/PrintTestCommand.php` (`print:test`): Test slip CLI sending diagnostic page directly to spooler without writing database records.
4. **Payment Service Integration:**
   - `app/Services/PaymentService.php`: Injects `PrintService`, records `cashier_name` on `PaymentTransaction`, and safely invokes `queueOrderReceipt` inside payment settlement. Wrapped in try/catch to guarantee payment atomicity (print failure never reverts payment).

### Automated Tests Added
- `tests/Unit/Printing/EscPosBuilderTest.php` (23 tests, 29 assertions)
- `tests/Unit/Printing/ReceiptRendererServiceTest.php` (4 tests, 22 assertions)
- `tests/Unit/Printing/KitchenTicketRendererServiceTest.php` (1 test, 12 assertions)
- `tests/Feature/Printing/PrintServiceQueueTest.php` (5 tests, 13 assertions)
- `tests/Feature/Printing/PrintWorkerExecutionTest.php` (5 tests, 54 assertions)
- `tests/Feature/Printing/CrashRecoveryTest.php` (3 tests, 9 assertions)
- `tests/Feature/Printing/ManualRetryAndReprintTest.php` (7 tests, 26 assertions)

### Final Test Results
- **New Task 5.5 Tests:** 48 tests, 165 assertions — 100% passing.
- **Complete Test Suite:** 245 tests, 861 assertions — 100% passing (0 failures, 0 errors).
- **Code Formatting:** Laravel Pint format clean.
- **Frontend Build:** `npm run build` completed cleanly (Vite 8.3.8, 669ms).
- **Development Database:** Preserved untouched; migration batch intact.

### Remaining Hardware Verification (Production Host)
1. Verify physical USB cable connection to thermal printer `POS80 Printer` on virtual port `CP001`.
2. Run test print: `php artisan print:test --printer="POS80 Printer"`.
3. Configure Windows Task Scheduler task for `php artisan print:work` with setting "If the task is already running, do not start a new instance".


---

## Phase 6 — Task 6.1: Inventory Database Foundation & Models

**Date:** 2026-11-15
**Status:** ✅ COMPLETED

### Summary

Established the complete Phase 6 inventory management database schema and all corresponding Eloquent models. This task delivers the data layer only — no UI, deduction logic, or seeder data for the live database.

### Deliverables

#### New Migration (Batch 10, already applied)
- **File:** `database/migrations/2026_11_15_000001_create_phase6_inventory_management_tables.php`
- 6 new tables created with MySQL/SQLite cross-driver compatibility:
  1. `ingredient_categories` — categorisation of raw ingredients
  2. `ingredients` — raw materials with DECIMAL(12,3) stock quantities, soft deletes, cost tracking
  3. `ingredient_balances` — per-branch current stock (NULL = default single store)
  4. `recipe_items` — product BOM: each ingredient consumed per unit of product/variant
  5. `modifier_recipe_items` — ingredient changes triggered by modifier selection (add / replace)
  6. `stock_movements` — append-only audit ledger of all stock changes

#### Critical Constraint Design
- **`ingredient_balances`**: NULL-safe uniqueness enforced via `GENERATED ALWAYS AS (COALESCE(branch_id, 0)) STORED` generated column + `UNIQUE KEY uk_branch_ingredient`. MySQL allows NULL != NULL in UNIQUE indexes; the generated column collapses NULL → 0 to enforce global store uniqueness.
- **`recipe_items`**: Same pattern — `GENERATED ALWAYS AS (COALESCE(product_variant_id, 0)) STORED` → `UNIQUE KEY uk_recipe_item (product_id, variant_slot, ingredient_id)`. Allows one base recipe (NULL variant) and one override per variant per ingredient.
- **`stock_movements`**: `UNIQUE KEY uk_stock_idempotency_ingredient (idempotency_key, ingredient_id)` — MySQL NULL != NULL lets non-idempotent adjustments use `idempotency_key = NULL` freely, while idempotent order deductions are uniquely enforced per key+ingredient.
- **SQLite tests**: Equivalent `CREATE UNIQUE INDEX` with functional `COALESCE(col, 0)` expressions for in-memory test database.

#### New Models Created
| File | Description |
|---|---|
| `app/Models/IngredientCategory.php` | Category, `ingredients()` HasMany, `ordered()` scope |
| `app/Models/Ingredient.php` | Full model with `toMilli()`/`fromMilli()` static helpers (exact string arithmetic, zero float) |
| `app/Models/IngredientBalance.php` | Balance with `current_stock_milli` accessor, `setCurrentStockMilli()`, `isLowStock()` |
| `app/Models/RecipeItem.php` | Recipe entry with `quantity_milli` accessor, `setQuantityMilli()` |
| `app/Models/ModifierRecipeItem.php` | Modifier recipe entry with `ACTION_ADD`/`ACTION_REPLACE` constants |
| `app/Models/StockMovement.php` | Append-only via `booted()` guard (throws `DomainException` on update/delete), no `updated_at` |

#### Existing Models Updated
| File | Change |
|---|---|
| `app/Models/Product.php` | Added `recipeItems(): HasMany` |
| `app/Models/ProductVariant.php` | Added `recipeItems(): HasMany` (via `product_variant_id` FK) |
| `app/Models/Modifier.php` | Added `modifierRecipeItems(): HasMany` |

#### New Factories Created
- `database/factories/IngredientCategoryFactory.php`
- `database/factories/IngredientFactory.php`
- `database/factories/IngredientBalanceFactory.php`
- `database/factories/RecipeItemFactory.php`
- `database/factories/ModifierRecipeItemFactory.php`
- `database/factories/StockMovementFactory.php`

#### New Test File
- `tests/Feature/InventoryFoundationTest.php` — 38 tests, 72 assertions covering:
  - All 6 tables exist with correct columns and soft-delete behaviour
  - `uk_branch_ingredient` uniqueness (NULL-safe, branch-aware)
  - `uk_recipe_item` uniqueness (base vs variant-specific, NULL-safe)
  - `uk_stock_idempotency_ingredient` (NULL keys allow duplicates; keyed rows enforced)
  - `StockMovement` append-only guard (`update()` and `delete()` both throw `DomainException`)
  - `Ingredient::toMilli()` / `fromMilli()` precision (round-trip, edge cases, error cases)
  - All 8 new relationships load correctly
  - `IngredientBalance::isLowStock()` returns correct boolean

### Decimal Quantity Design

All ingredient quantities stored as `DECIMAL(12,3)` and cast as PHP `'string'` in Eloquent — never `'decimal:3'` (which internally creates PHP `float`). Arithmetic uses:

```php
// Split on '.' — no (float) cast at any point
Ingredient::toMilli('18.500')  // → 18500 (int milli-units)
Ingredient::fromMilli(18500)   // → '18.500' (decimal string)
```

### Automated Test Results
- **New Inventory Tests:** 38 tests, 72 assertions — 100% passing
- **Complete Test Suite:** 317 tests, 1077 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint:** ✅ Clean (0 violations after auto-format)
- **Development Database:** Preserved; migration batch 10 applied and confirmed

### Scope Boundaries Respected
- No UI (Livewire), no inventory deduction logic, no PaymentService integration
- No live-database seeder data
- No modifications to: `CashierPos`, `PaymentService`, `RefundService`, `PrintService`

---

## Phase 6 — Task 6.2: Ingredient & Recipe Management UI

**Date:** 2026-11-21  
**Status:** ✅ COMPLETED

### Summary
Built the complete Owner backoffice management interface for raw materials, ingredient categories, product recipes (Bill of Materials), and modifier recipe delta rules. All views adhere strictly to Temu Pulang warm-toned design tokens (`bg-cream-50`, `coffee-950`), Alpine.js modal patterns, and `role:owner` security middleware.

### Deliverables

#### New Livewire Components & Views
| Component | Blade View | Description |
|---|---|---|
| `app/Livewire/Inventory/IngredientManager.php` | `resources/views/livewire/inventory/ingredient-manager.blade.php` | Raw material catalog & category management, unit selection, cost per unit, minimum stock alerts, initial balance posting with `TYPE_OPENING_STOCK` movement, audit logging |
| `app/Livewire/Inventory/RecipeManager.php` | `resources/views/livewire/inventory/recipe-manager.blade.php` | Catalog-wide recipe overview, per-serving recipe breakdown, calculated COGS, and gross margin percentage metrics |

#### Existing Components Updated
| File | Changes Made |
|---|---|
| `app/Livewire/Menu/ProductForm.php` | Added Tab 5 (`$activeTab = 'recipe'`), base recipe item repeater (`addBaseRecipeItem`, `removeBaseRecipeItem`), variant-specific override repeaters (`addVariantRecipeItem`, `removeVariantRecipeItem`), and transactional sync on `save()` |
| `resources/views/livewire/menu/product-form.blade.php` | Rewritten 5-tab interface including Tab 5 for interactive Recipe / BOM configuration with ingredient selects and decimal quantity inputs |
| `app/Livewire/Menu/ModifierManager.php` | Added recipe rule fields (`ingredientId`, `recipeAction`, `replacesIngredientId`, `quantity`) for modifier options, synced into `modifier_recipe_items` on `save()` |
| `resources/views/livewire/menu/modifier-manager.blade.php` | Added recipe action controls (`Tambah Bahan` / `Ganti Bahan Baku`) and quantity inputs per modifier option in modal |
| `routes/web.php` | Added authenticated, owner-only routes `/inventory/ingredients` and `/inventory/recipes` |
| `resources/views/components/layouts/app.blade.php` | Added "Inventaris & Bahan" navigation section with links to "Bahan Baku & Stok" and "Resep Menu (BOM)" |

#### New Feature Test File
- `tests/Feature/Inventory/IngredientAndRecipeManagementTest.php` — 14 tests, 41 assertions:
  1. `test_owner_can_access_ingredient_manager` (Owner access 200)
  2. `test_owner_can_access_recipe_manager` (Owner access 200)
  3. `test_cashier_cannot_access_ingredient_manager` (Cashier redirected to POS)
  4. `test_cashier_cannot_access_recipe_manager` (Cashier redirected to POS)
  5. `test_guest_redirected_to_login` (Guests redirected to login)
  6. `test_owner_can_create_ingredient_category` (Category creation & DB persistence)
  7. `test_owner_can_create_ingredient_with_initial_stock` (Ingredient creation, balance creation, opening stock movement)
  8. `test_owner_can_edit_ingredient` (Editing ingredient details & min stock alert)
  9. `test_owner_can_toggle_ingredient_active_status` (Active status toggle & audit logging)
  10. `test_owner_can_soft_delete_ingredient` (Soft deletion)
  11. `test_owner_can_configure_base_recipe_for_single_product` (ProductForm base BOM sync)
  12. `test_owner_can_configure_variant_specific_recipe_overrides` (ProductForm variant-specific BOM sync)
  13. `test_recipe_manager_displays_products_and_cogs` (RecipeManager COGS & Gross Margin calculation)
  14. `test_modifier_manager_saves_ingredient_recipe_rules` (ModifierManager recipe rule sync)

### Automated Test Results
- **Task 6.2 Tests:** 14 tests, 41 assertions — 100% passing
- **Inventory Foundation Tests:** 38 tests, 72 assertions — 100% passing
- **Full Test Suite:** 331 tests, 1118 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint:** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully (636ms)

### Scope Boundaries Respected
- Management UI only: No automatic POS stock deduction engine (Task 6.4)
- No manual stock-in/adjustment forms (reserved for Task 6.3)
- No refund/cancellation inventory restoration logic (reserved for Task 6.7)
- Zero destructive database operations (no `migrate:fresh`, no `db:wipe`)

---

## Phase 5 — Task 5.6 (Part 1 of 4): CashierPos Localization & Owner Print Job Resolution UI

**Date:** 2026-11-24  
**Status:** ⏳ IN PROGRESS (Part 1 Completed; Task 5.6 Overall Planned / In Progress)

### Summary
Implemented Part 1 of 4 for Task 5.6:
1. **Full Localization of CashierPos:** Extracted all user-facing strings, checkout notifications, payment error messages, validation alerts, and blade modal elements from `app/Livewire/Pos/CashierPos.php` and `resources/views/livewire/pos/cashier-pos.blade.php` into `lang/id/messages.php` and `lang/en/messages.php`. Mapped known payment and shift domain exceptions (closed shift, conflict 409, insufficient cash, QRIS invalid/unconfirmed, order already paid, order cancelled) to user-friendly translated messages while logging unhandled system errors.
2. **Owner Print Job Resolution Interface:** Built an owner-only Livewire component (`App\Livewire\Printing\PrintJobIndex`) and view to monitor problematic print jobs (`failed` and `uncertain`). Owners can manually resolve uncertain jobs to `completed` or `failed` with a mandatory resolution note via `PrintService::resolveUncertainJob()`, recording full audit trail logs. Enforced `role:owner` middleware on the route and re-checked authorization within Livewire actions.
3. **POS Receipt Status Notice:** In the POS checkout success modal, `uncertain` print status instructs the cashier in both Indonesian and English to inspect the physical printer and contact the Shop Owner.

### Deliverables

#### New Files Created
- `app/Livewire/Printing/PrintJobIndex.php` — Owner-only Livewire component for listing and resolving `failed` and `uncertain` print jobs.
- `resources/views/livewire/printing/print-job-index.blade.php` — Table view with status badges, status filter, and resolution modal with required notes.
- `tests/Feature/Printing/OwnerPrintJobResolutionTest.php` — 11 automated feature tests (34 assertions) verifying localization, owner resolution, and cashier authorization guards.

#### Files Modified
- `app/Livewire/Pos/CashierPos.php` — Replaced hardcoded Indonesian strings with `__('messages.pos.*')`, added `mapPaymentExceptionToMessage()`.
- `resources/views/livewire/pos/cashier-pos.blade.php` — Localized cart, category badges, payment modal, and success modal.
- `lang/id/messages.php` — Added `messages.nav.print_jobs`, all `messages.pos.*`, `messages.common.unauthorized`, and `messages.printing.*`.
- `lang/en/messages.php` — Added corresponding English translations for all navigation, POS, common, and printing keys.
- `routes/web.php` — Registered `/printing/jobs` under `auth` and `role:owner` middleware.
- `resources/views/components/layouts/app.blade.php` — Added sidebar link to `Antrean Cetak` / `Print Jobs` in the Owner Administration section.

#### Automated Test Results
- **New Print Job Resolution Tests:** 11 tests, 34 assertions — 100% passing
- **POS Checkout Integration Tests:** 23 tests, 122 assertions — 100% passing
- **Full Test Suite:** 342 tests, 1152 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint:** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 1.03s

---

## Phase 5 — Task 5.6 (Part 2A of 4): Owner QRIS Image Upload & POS Display

**Date:** 2026-11-25  
**Status:** ⏳ IN PROGRESS (Part 2A Completed; Task 5.6 Overall Remains In Progress)

### Summary
Implemented Part 2A of 4 for Task 5.6 (QRIS image management and POS display only; cash payment panel untouched):
1. **Owner-Only QRIS Image Management (`App\Livewire\Settings\ShopSettingsManager`):** Added a `QRIS` tab allowing Owners to upload, preview, replace, and remove the shop's QRIS image. Enforced `isOwner()` authorization (`abort(403)` for cashiers), PNG/WebP validation with real MIME-type inspection via `finfo`, 2 MB max size, storage on the `public` disk under `storage/app/public/qris`, automatic deletion of superseded files on replace/remove, persistence in `application_settings` (`key: payment.qris_image_path`, `group: payment`, `type: string`), and `ActivityLog` auditing.
2. **POS QRIS Modal Display (`App\Livewire\Pos\CashierPos` & Blade):** When QRIS is selected in the checkout modal, displays the uploaded QRIS image large alongside the prominent total amount to pay, or a localized notice instructing the cashier to ask the Shop Owner to upload one if unset. Existing confirmation checkbox and reference number flow remain unchanged.
3. **Bilingual Localization:** Added identical translation keys to `lang/id/messages.php` and `lang/en/messages.php` under `messages.pos.*` and `messages.settings.*`.

### Deliverables

#### New Files Created
- `tests/Feature/QrisImageSettingsTest.php` — 8 feature tests (45 assertions) covering PNG/WebP upload, JPG/GIF/SVG/oversized/spoofed-extension rejection, old-file deletion on replace, setting clearing on remove, Cashier `403` guard, and POS display/fallback notice.

#### Files Modified
- `app/Livewire/Settings/ShopSettingsManager.php` — Added `WithFileUploads`, `$qrisImageUpload`, `$qrisImagePath`, `uploadQrisImage()`, and `removeQrisImage()`.
- `resources/views/livewire/settings/shop-settings-manager.blade.php` — Added the `QRIS` tab and upload/preview/remove UI.
- `app/Livewire/Pos/CashierPos.php` — Passed `qrisImagePath` from `ApplicationSetting` to the view in `render()`.
- `resources/views/livewire/pos/cashier-pos.blade.php` — Rendered the QRIS image + prominent amount to pay (or localized missing-image notice) inside the QRIS payment panel.
- `lang/id/messages.php` & `lang/en/messages.php` — Added matching QRIS settings and POS keys.
- `docs/ARCHITECTURE.md`, `docs/DATABASE_DESIGN.md`, `docs/DEVELOPMENT_LOG.md` — Documented `payment.qris_image_path` and Part 2A deliverables.

#### Automated Test Results
- **QRIS Image Settings Tests (`QrisImageSettingsTest`):** 8 tests, 45 assertions — 100% passing
- **Full Test Suite (`php artisan test --compact`):** 352 tests, 1199 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 1.05s

---

## Phase 5 — Task 5.6 (Part 2B of 4): Dynamic Cash Quick-Amount Suggestions

**Date:** 2026-11-25  
**Status:** ⏳ IN PROGRESS (Part 2B Completed; Task 5.6 Overall Remains In Progress)

### Summary
Implemented Part 2B of 4 for Task 5.6:
- Added `CashierPos::suggestCashAmounts(int $total): array` using pure integer arithmetic (`intdiv`) over `[5000, 10000, 20000, 50000, 100000]` to compute up to 4 unique, ascending quick-cash amounts strictly greater than the order total.
- Replaced the hardcoded `Rp 50.000` and `Rp 100.000` buttons in `resources/views/livewire/pos/cashier-pos.blade.php` with `Uang Pas` first followed by `$cashSuggestions`. Clicking a button only sets `cashGiven` without touching the cart, checkout idempotency key, or in-flight payment state.

### Deliverables
- **Created:** `tests/Unit/PosCashSuggestionTest.php` (3 unit tests), `tests/Feature/PosCashQuickButtonsTest.php` (1 feature test)
- **Modified:** `app/Livewire/Pos/CashierPos.php`, `resources/views/livewire/pos/cashier-pos.blade.php`, `docs/DEVELOPMENT_LOG.md`

### Automated Test Results
- **Full Test Suite (`php artisan test --compact`):** 356 tests, 1275 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 608ms

---

## Phase 5 — Task 5.6 (Part 2C): Cash Received Input & Blade String Cleanup

**Date:** 2026-11-25  
**Status:** ⏳ IN PROGRESS (Part 2C Completed; Task 5.6 Overall Remains In Progress)

### Summary
Implemented Part 2C for Task 5.6:
- Confirmed via `git diff` that no typed cash input existed in `resources/views/livewire/pos/cashier-pos.blade.php` prior to Part 2B.
- Added an Alpine-formatted numeric `cashGiven` input (`inputmode="numeric"`, `Rp` prefix, thousand-separator display in `id-ID`, digit-only stripping capped at 10 digits, empty -> `0`, auto-focused when cash is selected, synced live to integer `cashGiven`) above the quick-amount buttons.
- Replaced the leftover hardcoded `title="Hapus"` and `(Wajib Pilih 1)` / `(Opsional)` strings in `cashier-pos.blade.php` with `__('messages.pos.delete_item')`, `__('messages.pos.required_select_one')`, and `__('messages.pos.optional')`.

### Deliverables
- **Modified:** `resources/views/livewire/pos/cashier-pos.blade.php`, `tests/Feature/PosCashQuickButtonsTest.php`, `docs/DEVELOPMENT_LOG.md`

### Automated Test Results
- **Full Test Suite (`php artisan test --compact`):** 357 tests, 1294 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 901ms

---

## Phase 5 — Task 5.6 (Part 3 of 4): Owner-Only Refund Screen

**Date:** 2026-11-25  
**Status:** ⏳ IN PROGRESS (Part 3 Completed; Task 5.6 Overall Remains In Progress)

### Summary
Implemented Part 3 of 4 for Task 5.6:
- Built `App\Livewire\Orders\OrderRefund` and `resources/views/livewire/orders/order-refund.blade.php` at `/orders/{order}/refund` (`role:owner` middleware + `isOwner()` checks in `mount()` and `submitRefund()`).
- Shows order payments with `remainingRefundableBalance()`, Alpine-formatted integer Rupiah inputs, `refund_method` (`cash`, `qris_reversal`, `manual_transfer`), required reason, stable `idempotencyKey` preserved on retry, active shift guard for cash refunds, and translated error masking with `Log::error` fallback.
- Added an Owner-only refund link in `order-index.blade.php` and identical `messages.refund.*` keys in `lang/id/messages.php` and `lang/en/messages.php`.

### Deliverables
- **Created:** `app/Livewire/Orders/OrderRefund.php`, `resources/views/livewire/orders/order-refund.blade.php`, `tests/Feature/Refund/OrderRefundScreenTest.php` (9 tests, 30 assertions)
- **Modified:** `routes/web.php`, `resources/views/livewire/orders/order-index.blade.php`, `lang/id/messages.php`, `lang/en/messages.php`, `docs/ARCHITECTURE.md`, `docs/DEVELOPMENT_LOG.md`

### Automated Test Results
- **Order Refund Screen Tests (`OrderRefundScreenTest`):** 9 tests, 30 assertions — 100% passing
- **Full Test Suite (`php artisan test --compact`):** 366 tests, 1324 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 760ms

---

## Phase 5 — Task 5.6 (Consolidated Summary): POS Checkout, QRIS Image, Cash Input & Owner Refund UI

**Date:** 2026-11-24 to 2026-11-25  
**Status:** ✅ COMPLETED

### Summary
Completed all parts of Task 5.6 across the POS checkout, settings, printing resolution, and refund workflows:
- **Initial POS Settlement Integration:** Wired `CashierPos` to `OrderService::createOrder()` and `PaymentService::settlePayment()` with `PosCheckoutIntegrationTest.php` (23 tests, 122 assertions).
- **Part 1 — POS Localization & Owner Print Job Resolution:** Localized `CashierPos` and `cashier-pos.blade.php` across `lang/id` and `lang/en`, added `mapPaymentExceptionToMessage()`, and built `App\Livewire\Printing\PrintJobIndex` (`/printing/jobs`) with `OwnerPrintJobResolutionTest.php` (13 tests, 36 assertions).
- **Part 2A — QRIS Image Upload & POS Display:** Added Owner-only QRIS upload/preview/replace/remove in `ShopSettingsManager` (`payment.qris_image_path`, PNG/WebP, real MIME validation via `finfo`, max 2 MB, `storage/app/public/qris`) and QRIS modal display in `CashierPos` (`QrisImageSettingsTest.php`: 8 tests, 45 assertions).
- **Part 2B — Computed Quick-Cash Buttons:** Added pure integer `CashierPos::suggestCashAmounts(int $total)` (`PosCashSuggestionTest.php`: 3 tests, 47 assertions) replacing hardcoded `Rp 50.000` / `Rp 100.000` buttons.
- **Part 2C — Typed Cash Received Input & Blade Cleanup:** Added Alpine-formatted numeric `cashGiven` input (`id-ID` thousand separators, digit-only stripping) and localized remaining hardcoded strings in `cashier-pos.blade.php` (`PosCashQuickButtonsTest.php`: 2 tests, 24 assertions).
- **Part 3 — Owner-Only Refund Screen:** Built `App\Livewire\Orders\OrderRefund` (`/orders/{order}/refund`) with per-payment `remainingRefundableBalance()`, `refund_method`, required reason, stable `idempotencyKey`, active shift guard for cash refunds, and exception masking (`OrderRefundScreenTest.php`: 9 tests, 30 assertions).

*Suite Growth Note:* At the end of Task 5.5, the suite stood at **245 tests (861 assertions)**. The suite grew to **367 tests (1,361 assertions)** through `PosCheckoutIntegrationTest` (+23 tests), Phase 6 Tasks 6.1–6.2 (+52 tests across `InventoryFoundationTest` and `IngredientAndRecipeManagementTest`), Task 5.6 Parts 1–3 (+46 tests across `OwnerPrintJobResolutionTest`, `QrisImageSettingsTest`, `PosCashSuggestionTest`, `PosCashQuickButtonsTest`, and `OrderRefundScreenTest`), and Task 5.7 (+1 end-to-end test).

---

## Phase 5 — Task 5.7: Full Phase 5 Verification & Hardening

**Date:** 2026-11-25  
**Status:** ✅ COMPLETED

### Summary
Completed final Phase 5 end-to-end verification and documentation synchronization:
- Added `tests/Feature/Phase5EndToEndTest.php` executing one continuous lifecycle: open shift -> manual cash movements -> order creation -> split payment settlement (cash + QRIS) -> fake spooler print job completion -> partial cash refund -> full refund -> shift close, asserting order status, `payment_status`, expected cash (`opening + manual in - manual out + net cash sales - cash refunds`), append-only immutability, and `ActivityLog` entries at each step.
- Synchronized `PROJECT_PLAN.md`, `DEVELOPMENT_LOG.md`, `DATABASE_DESIGN.md` (fixed Phase 6 `### 6.1`–`### 6.6` sub-section numbering), and `ARCHITECTURE.md`.

### Deliverables
- **Created:** `tests/Feature/Phase5EndToEndTest.php` (1 test, 37 assertions)
- **Modified:** `docs/PROJECT_PLAN.md`, `docs/DEVELOPMENT_LOG.md`, `docs/DATABASE_DESIGN.md`, `docs/ARCHITECTURE.md`

### Automated Test Results
- **Phase 5 End-to-End Test (`Phase5EndToEndTest`):** 1 test, 37 assertions — 100% passing
- **Full Test Suite (`php artisan test --compact`):** 367 tests, 1361 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 1.05s

---

## Phase 6 — Task 6.3: Stock-In & Adjustment Interface

**Date:** 2026-11-25  
**Status:** ✅ COMPLETED

### Summary
Implemented the Owner-only manual stock movement service and UI for Phase 6 Task 6.3:
- **Transactional Stock Service (`App\Services\Inventory\StockAdjustmentService`):** Implemented `stockIn()`, `recordWastage()`, and `adjust()` (`counted` and `difference` modes) inside `DB::transaction()` with `firstOrCreate()` + `lockForUpdate()` on `ingredient_balances` (`branch_id = NULL`), exact `Ingredient::toMilli()` / `fromMilli()` integer arithmetic (no PHP floats), `(idempotency_key, ingredient_id)` deduplication, and typed exceptions (`InvalidStockQuantityException`, `InsufficientStockException`, `StockReasonRequiredException`).
- **Owner-Only Stock UI (`App\Livewire\Inventory\StockAdjustmentManager` at `/inventory/stock`):** Protected with `role:owner` middleware and per-action `isOwner()` checks; linked under `Inventaris & Bahan`. Includes ingredient search picker, comma/dot decimal input (up to 3 decimals, no thousand separators), Alpine-formatted Rupiah unit cost for stock-in, stable idempotency keys, result summary card, and `ActivityLog` auditing (`module: 'inventory'`, actions `stock_in`, `stock_wastage`, `stock_adjustment`).
- **Bilingual Localization:** Added identical `messages.nav.stock` and `messages.stock.*` keys in `lang/id/messages.php` and `lang/en/messages.php`.

### Deliverables
- **Files Created:**
  - `app/Exceptions/Inventory/InvalidStockQuantityException.php`
  - `app/Exceptions/Inventory/InsufficientStockException.php`
  - `app/Exceptions/Inventory/StockReasonRequiredException.php`
  - `app/Services/Inventory/StockAdjustmentService.php`
  - `app/Livewire/Inventory/StockAdjustmentManager.php`
  - `resources/views/livewire/inventory/stock-adjustment-manager.blade.php`
  - `tests/Unit/Inventory/StockQuantityParsingTest.php` (3 tests, 18 assertions)
  - `tests/Feature/Inventory/StockAdjustmentTest.php` (7 tests, 52 assertions)
- **Files Modified:**
  - `routes/web.php`, `resources/views/components/layouts/app.blade.php`, `lang/id/messages.php`, `lang/en/messages.php`, `docs/PROJECT_PLAN.md`, `docs/DEVELOPMENT_LOG.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE_DESIGN.md`

### Automated Test Results
- **Unit & Feature Stock Tests:** 10 tests, 70 assertions — 100% passing
- **Full Test Suite (`php artisan test --compact`):** 377 tests, 1431 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 1.54s

### Scope Boundaries Respected
- Supplier records are not implemented in this task; supplier names or invoice numbers can be entered in the optional `stock_in` `notes` field.
- `ingredients.cost_per_unit` is not modified by `stock_in`.
- Automatic order deduction, low-stock alerts, stock movement history viewer, and refund inventory rules (Tasks 6.4–6.7) remain unimplemented.

---

## Phase 6 — Task 6.4: Automatic Order Deduction

**Date:** 2026-11-25  
**Status:** ✅ COMPLETED

### Summary
Implemented automatic post-commit inventory deduction upon payment settlement for Phase 6 Task 6.4:
- **Recipe Resolution (`App\Services\Inventory\RecipeResolutionService`):** Resolves per-ingredient milli-units from base `RecipeItem` (`product_variant_id IS NULL`), variant overrides (replacing matching base ingredients while keeping base-only items and adding variant-only items), and modifier deltas (`add` adds quantity; `replace` clears the replaced ingredient's per-unit quantity to `0`—never below `0`—and adds the replacement quantity), multiplied by `OrderItem.quantity`. Skips items whose product/variant no longer exists.
- **Inventory Deduction (`App\Services\Inventory\InventoryService` + `StockAdjustmentService::recordOrderDeductionRow()`):** Runs in its own `DB::transaction()` after payment commit, creates missing single-store `ingredient_balances` rows, locks balances in ascending `ingredient_id` order, and records one `order_deduction` `StockMovement` per ingredient (`reference_type = 'orders'`, `reference_id = order.id`, negative `quantity`, `idempotency_key = PaymentTransaction.ulid`).
- **Non-Blocking Payment Hook & Retry Command:** Added minimal post-commit + replay-branch calls in `PaymentService::attemptInventoryDeduction()` wrapped in `try/catch(\Throwable)` logging `order_deduction_failed` in `ActivityLog` on error. Added `php artisan inventory:retry-deductions {--apply}` (dry-run by default) to recover failed deductions without touching legacy orders.

### Deliverables
- **Files Created:**
  - `app/Services/Inventory/RecipeResolutionService.php`
  - `app/Services/Inventory/InventoryService.php`
  - `app/Console/Commands/RetryInventoryDeductionsCommand.php`
  - `tests/Unit/Inventory/RecipeResolutionServiceTest.php` (4 tests, 14 assertions)
  - `tests/Feature/Inventory/OrderDeductionTest.php` (5 tests, 26 assertions)
- **Files Modified:**
  - `app/Services/Inventory/StockAdjustmentService.php`, `app/Services/PaymentService.php`, `docs/PROJECT_PLAN.md`, `docs/DEVELOPMENT_LOG.md`, `docs/ARCHITECTURE.md`, `docs/DATABASE_DESIGN.md`

### Automated Test Results
- **Unit & Feature Deduction Tests:** 9 tests, 40 assertions — 100% passing
- **Existing Payment/POS/Refund/E2E Suites (Unmodified):** 100% passing
- **Full Test Suite (`php artisan test --compact`):** 386 tests, 1471 assertions — 100% passing (0 failures, 0 errors)
- **Laravel Pint (`php vendor/bin/pint --test`):** ✅ Clean (0 violations)
- **Vite Build (`npm run build`):** ✅ Compiled successfully in 710ms

### Scope Boundaries Respected
- Deduction runs after payment commit and never blocks, delays, or rolls back a payment.
- Stock balances (`ingredient_balances.current_stock`) may go below zero.
- Refunds and cancellations do not restock inventory until Task 6.7.
- Orders paid before this feature are never deducted retroactively.








