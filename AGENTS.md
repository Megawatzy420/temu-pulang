# TEMU PULANG — AGENTS GUIDELINES & OPERATIONAL DIRECTIVES

This document serves as the persistent, authoritative instruction file for all AI coding agents, developers, and automated tools working on the **Temu Pulang** repository. All rules herein are mandatory.

---

## 1. Project Identity & Context

- **Project Name:** Temu Pulang
- **Purpose:** Local-first Point of Sale (POS) and business management system tailored for Food & Beverage (F&B) operations.
- **Operating Environment:** Single physical shop, Windows 11 server, local Wi-Fi / LAN environment.
- **Offline-First Mandate:** Core operations (ordering, cashier shifts, cash drawer ledger, receipt printing, catalog browsing) must never depend on active cloud services or internet connectivity.
- **Repository Root:** `C:\xampp\htdocs\temu-pulang`

---

## 2. Technology Stack & Confirmed Versions

Always verify installed package versions against `composer.json` and `package.json` before writing code:

- **Framework:** Laravel 13 (v13.42.0)
- **Runtime:** PHP 8.5 (v8.5.12)
- **Reactivity / Frontend:** Livewire 4 (v4.4.8) + Alpine.js (bundled with Livewire 4)
- **Styling:** Tailwind CSS 4 (v4.0.0 / `@tailwindcss/vite`)
- **Build Tool:** Vite 8 (v8.3.8)
- **Database:** MySQL 8 / MariaDB 10.11 (`temu_pulang`)
- **Testing Database:** SQLite In-Memory (`:memory:` isolated via `phpunit.xml`)
- **Code Formatter:** Laravel Pint (v1.27)
- **Test Runner:** PHPUnit (v12.5.12)

---

## 3. Architecture Rules

- **Laravel Standard Conventions:** Follow established Laravel conventions. Use `php artisan make:*` commands with `--no-interaction` when generating artifacts.
- **Livewire for Interactive UI:** Use Livewire components for dynamic views (POS, shift management, settings, audit log viewer). Utilize Alpine.js for lightweight client-side state (modals, dropdowns, keyboard navigation).
- **Service Layer Pattern:** Keep core business logic out of controllers and Livewire components:
  - `App\Services\ShiftService`: Shift open/close lifecycles, expected cash balance, concurrency guards.
  - `App\Services\CashMovementService`: Cash drawer ledger transactions, immutable reversal logic.
- **Eloquent Relationships & Transactions:**
  - Always enforce relational integrity with explicit foreign keys.
  - Wrap multi-step mutations and financial operations in `DB::transaction(...)`.
  - Use pessimistic locking (`lockForUpdate()`) and parent row locking to serialize concurrent critical paths.
- **Minimal Dependencies:** Never introduce external third-party packages or architectural patterns without explicit user authorization.

---

## 4. Business & Financial Rules

- **Currency:** Indonesian Rupiah (IDR).
- **Zero Floating-Point Drift:**
  - Store all monetary values as integer Rupiah using `BIGINT UNSIGNED`. Floating-point types (`FLOAT`, `DOUBLE`, `DECIMAL` without integer conversion) are strictly forbidden for monetary storage and calculation.
- **Financial Defaults:**
  - **Tax Rate:** `0%` default (stored as `0` basis points in `shop_settings.tax_rate`).
  - **Service Charge:** `0%` default (stored as `0` basis points in `shop_settings.service_charge_rate`).
  - Both rates are configurable only by the Owner. Changes must never silently recalculate historical records.
- **Timezone:** Strictly `Asia/Jakarta` (WIB, UTC+7).
- **Application Localization:**
  - **Default Language:** Indonesian (`id`).
  - **Secondary Language:** English (`en`).
  - Application maintains session-based language switching (`/locale/{locale}`).
- **User Roles:**
  - **Owner (`owner`):** Unrestricted access, bypasses all permissions via `Gate::before`, manages settings, user accounts, shifts, catalog, and audit logs.
  - **Cashier (`cashier`):** Restricted access, operates POS and permitted shift functions. Strictly prohibited from settings, financial margin reports, catalog editing, and audit logs.
  - *Note:* Do not seed or activate a `supervisor` role during early phases.
- **Order Types & Table Rules:**
  - Supported order types: **Dine-in** and **Takeaway**.
  - **No Table Assignment:** There are no table numbers, seat selectors, or table floor plans.
- **Hardware & Printing:**
  - **Printer Connection:** Direct **USB** connected to the Windows host.
  - **Receipt Paper Width:** Supports 58mm and 80mm thermal paper.
  - Network IP and TCP port settings must remain nullable and optional.

---

## 5. Security & Access Control

- **Server-Side Authorization:** Never rely on UI button hiding as the sole authorization check. Every restricted action must be validated on the server via middleware (`role:owner`), policies, or `abort(403)`.
- **LAN Boundary Protection:** MySQL/MariaDB must listen only on localhost or strictly firewalled internal interfaces. Never expose database ports directly to untrusted LAN nodes.
- **CSRF & Form Validation:** Ensure CSRF protection is active on all POST/PUT/DELETE forms. Use form requests or Livewire validation rules with localized error messages.
- **Audit Logging:** Record all security-sensitive events via `ActivityLog::record(...)`:
  - Authentication (login/logout, failed unauthorized access attempts).
  - Shift operations (open, close, discrepancy).
  - Cash ledger operations (creation, reversal).
  - Store settings modifications.
  - Never log passwords, tokens, or sensitive credentials.
- **Model Immutability Guards:**
  - Closed shifts cannot be modified or reopened (`Shift::booted()` throws on update).
  - Cash movements cannot be edited or deleted (`CashMovement::booted()` throws on update/delete).

---

## 6. Database Safety & Integrity Rules

- **Absolute Prohibition on Destructive Commands:**
  - **NEVER** run `php artisan migrate:fresh`.
  - **NEVER** run `php artisan db:wipe`.
  - **NEVER** run `DROP TABLE` or destructive database resets on working or development databases.
- **Data Preservation:** Never delete existing user, transaction, product, or shift data to solve a migration or coding problem.
- **Additive, Reversible Migrations:** Create new, safe migrations for schema additions. Include complete `down()` methods.
- **Database-Driver Awareness:**
  - Account for syntax differences between MariaDB/MySQL (development) and SQLite (testing).
  - When using raw SQL statements, check `Schema::getConnection()->getDriverName()`.
- **Pre-Migration Inspection:** Always run `php artisan migrate:status` and inspect current tables before applying new schema changes.
- **Isolated Testing Database:** All automated tests must run against the configured in-memory SQLite database (`RefreshDatabase`), never against the MySQL development database.

---

## 7. Standard Development Workflow

Before declaring any task or phase complete, follow this exact 9-step workflow:

1. **Read Documentation First:** Read `AGENTS.md`, `ARCHITECTURE.md`, `DATABASE_DESIGN.md`, and `PROJECT_PLAN.md`.
2. **Inspect Existing Code:** Inspect existing migrations, models, services, views, and seeders before writing or modifying code.
3. **Confirm Scope:** Strictly implement only the requested task and phase. Do not add unsolicited features.
4. **Implementation:** Write clean, typed PHP 8.5 code following existing conventions.
5. **Format Code:** Always run Laravel Pint to fix styling:
   ```bash
   php vendor/bin/pint --format agent
   ```
6. **Compile Frontend Assets:** When modifying Blade, CSS, or frontend assets, run:
   ```bash
   npm run build
   ```
7. **Run Automated Tests:** Run the full test suite and ensure 100% passing tests:
   ```bash
   php artisan test --compact
   ```
8. **Update Documentation:** Keep `ARCHITECTURE.md`, `DATABASE_DESIGN.md`, `DEVELOPMENT_LOG.md`, and `PROJECT_PLAN.md` synchronized with actual code changes.
9. **Final Handover:** Report verified items, files modified/created, test counts, passing assertions, and remaining risks.

---

## 8. Phase Management & Boundaries

Follow the roadmap defined in `docs/PROJECT_PLAN.md`:

- **Phase 1: Database Foundation** — Completed ✅
- **Phase 2: Authentication & Frontend Foundation** — Completed ✅
- **Phase 3: Operational Foundation** (Shifts, Cash Ledger, Settings, Audit Logs) — Completed ✅
- **Phase 4: Menu Management & Order Engine** — Completed ✅ (Hardening Complete)
- **Phase 5: Payments & Printing** — Planned ⏳
- **Phase 6: Inventory & Purchasing** — Planned ⏳
- **Phase 7: Finance & Analytics** — Planned ⏳
- **Phase 8: Deployment & Reliability** — Planned ⏳

**Critical Rules:**
- **Never automatically advance to the next phase.** Always stop at the phase boundary and wait for explicit user approval.
- **Distinguish Simulation from Reality:** Any UI elements built prior to real backend engines (e.g. mock payments, cart simulations) must be clearly flagged with a visible "SIMULASI DEMO" indicator and must not create fake transaction or inventory records.
- **Never claim a feature is complete without verifiable code and automated passing tests.**

---

## 9. Documentation Synchronization Mandate

Whenever an architectural, schema, or functional change is made, immediately update the corresponding documentation:

| Document | Responsibility |
|---|---|
| [`ARCHITECTURE.md`](file:///C:/xampp/htdocs/temu-pulang/docs/ARCHITECTURE.md) | High-level system architecture, service layer patterns, concurrency locking, and RBAC design. |
| [`DATABASE_DESIGN.md`](file:///C:/xampp/htdocs/temu-pulang/docs/DATABASE_DESIGN.md) | Table structures, column data types, foreign keys, constraints, indexes, and Mermaid ER diagrams. |
| [`DEVELOPMENT_LOG.md`](file:///C:/xampp/htdocs/temu-pulang/docs/DEVELOPMENT_LOG.md) | Historical chronology of deliverables, created/modified files, test counts, assertions, and known issues. |
| [`PROJECT_PLAN.md`](file:///C:/xampp/htdocs/temu-pulang/docs/PROJECT_PLAN.md) | 8-phase roadmap, current status, and detailed phase objectives. |
| [`AGENTS.md`](file:///C:/xampp/htdocs/temu-pulang/AGENTS.md) | Authoritative, actionable instructions for all agents working on the codebase. |
