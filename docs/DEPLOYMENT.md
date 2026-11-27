# Temu Pulang — Single-Shop Production Deployment & Operations Runbook

This runbook documents the production go-live procedure, account management commands, preflight checks, background worker setup, backup strategy, rehearsal checklist, downtime plan, and first-week operational routine for a single physical **Temu Pulang** shop running on a Windows 11 host PC over a local LAN.

---

## 1. Production `.env` Configuration (From `.env.production.example`)

Copy `.env.production.example` to `C:\xampp\htdocs\temu-pulang\.env` on the Windows 11 shop host PC (`Copy-Item .env.production.example .env`) and verify every value below:

```dotenv
APP_NAME="Temu Pulang"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=http://192.168.1.100
APP_TIMEZONE=Asia/Jakarta

APP_LOCALE=id
APP_FALLBACK_LOCALE=id
APP_FAKER_LOCALE=id_ID

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=temu_pulang
DB_USERNAME=temupulang_user
DB_PASSWORD=REPLACE_WITH_STRONG_LOCAL_DB_PASSWORD

SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

BACKUP_PATH="C:\backups\temu-pulang"
BACKUP_COPY_PATH="D:\temu-pulang-backups"
BACKUP_RETENTION_DAYS=14
MYSQLDUMP_PATH="C:\xampp\mysql\bin\mysqldump.exe"
MYSQL_CLIENT_PATH="C:\xampp\mysql\bin\mysql.exe"
BACKUP_MAX_AGE_HOURS=26
BACKUP_REQUIRED=true
```

> **Key Notes:**
> - `APP_ENV=production` disables quick demo login (`POST /login/demo/{role?}` returns `404`), hides demo credentials on `/login`, prevents `DatabaseSeeder` from seeding demo users or demo catalog products, and causes `ShopSettingSeeder` to seed neutral empty store contact details and a generic receipt footer (`Terima kasih atas kunjungan Anda`) instead of sample placeholders.
> - `APP_DEBUG=false` and `LOG_LEVEL=warning` prevent stack traces, environment variables, and verbose debug logs in production.
> - `DB_HOST=127.0.0.1` keeps MySQL/MariaDB strictly local to the Windows host.
> - `SESSION_LIFETIME=720` (12 hours) covers a full F&B cashier shift (`temupulang:preflight` warns if `SESSION_LIFETIME` is below `480` minutes).
> - `SESSION_SECURE_COOKIE=false` is required when serving over HTTP (`http://192.168.1.100`) on a local Wi-Fi/LAN without TLS.
> - **Login Throttling**: `POST /login` enforces a per-(lowercased email + IP) rate limit of 5 failed attempts per minute via `RateLimiter` and records an `ActivityLog` entry (`module: 'auth'`, `action: 'auth.login_throttled'`) without secrets when blocked.

---

## 2. Install, Migrate & Seed Essential Foundation Only

Open PowerShell in `C:\xampp\htdocs\temu-pulang` and run:

```powershell
# 1. Install production PHP dependencies without dev packages
composer install --no-dev --optimize-autoloader

# 2. Generate APP_KEY (ONLY on initial install before any data is encrypted)
php artisan key:generate --force

# 3. Inspect migration status and apply pending migrations
php artisan migrate:status
php artisan migrate --force

# 4. Seed ONLY essential roles/permissions and the default shop_settings row
# (Note: When APP_ENV=production, DatabaseSeeder also restricts itself to these two seeders)
php artisan db:seed --class=RoleAndPermissionSeeder --force
php artisan db:seed --class=ShopSettingSeeder --force
```

> **Warning:** Never run `migrate:fresh`, `migrate:rollback`, or `db:wipe` on a production or working database.

---

## 3. Public Storage Symlink (QRIS & Uploaded Images)

Create the symbolic link from `public/storage` to `storage/app/public` so uploaded QRIS static images (`storage/app/public/qris`) and product photos can be served by Apache:

```powershell
php artisan storage:link
```

*(Run PowerShell as Administrator on Windows 11 if symlink creation requires elevated privileges).*

---

## 4. Build Frontend Assets & Warm Laravel Caches

Compile the production Vite bundle (Tailwind CSS 4 + Alpine/Livewire assets) and cache Laravel configuration, routes, and Blade views:

```powershell
npm ci
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Whenever `.env` or any file under `config/`, `routes/`, or `resources/views/` is updated later, re-run `php artisan optimize:clear` followed by the three cache commands above.

---

## 5. Create Real Owner & Cashier Accounts

Passwords are **never** accepted as command-line arguments or options; the command prompts interactively with hidden input (minimum 10 characters) and confirmation.

### Create the Store Owner Account
```powershell
php artisan temupulang:create-user --role=owner --name="Nama Pemilik" --email="owner@tokokopi.id"
```

### Create Cashier Accounts (One Per Staff Member)
```powershell
php artisan temupulang:create-user --role=cashier --name="Kasir Pagi" --email="kasir.pagi@tokokopi.id"
php artisan temupulang:create-user --role=cashier --name="Kasir Sore" --email="kasir.sore@tokokopi.id"
```

### Reset a User's Password
```powershell
php artisan temupulang:set-password kasir.pagi@tokokopi.id
```

---

## 6. Deactivate Any Existing Demo Accounts

If the database was previously used in `local` mode and contains the seeded demo accounts (`owner@temupulang.local`, `cashier@temupulang.local`), deactivate them **after** creating at least one real active `owner` account:

```powershell
php artisan temupulang:deactivate-user owner@temupulang.local
php artisan temupulang:deactivate-user cashier@temupulang.local
```

- `temupulang:deactivate-user` refuses to deactivate the **last** active `owner` account so the store cannot lock itself out.
- To re-enable a deactivated account later, pass `--reactivate`:
  ```powershell
  php artisan temupulang:deactivate-user kasir.pagi@tokokopi.id --reactivate
  ```

---

## 7. Run the Go-Live Preflight Check

Before opening the store, run the read-only preflight verification command:

```powershell
php artisan temupulang:preflight
```

`temupulang:preflight` checks:
1. `APP_ENV` is `production` (`[WARN]` otherwise).
2. `APP_DEBUG` is `false` (`[FAIL]` if `true` in `production`).
3. `APP_KEY` is set (`[FAIL]`).
4. Database connection succeeds (`[FAIL]`).
5. `storage` and `bootstrap/cache` are writable (`[FAIL]`).
6. `public/storage` link exists (`[WARN]`).
7. Demo login is disabled (`[FAIL]` if enabled while `APP_ENV=production`).
8. At least one active `owner` user exists (`[FAIL]`).
9. No active user has a demo email (`owner@temupulang.local`, `cashier@temupulang.local`) or a demo password (`[FAIL]`).
10. `shop_settings` row exists (`[WARN]`), does **not** contain the seeder's sample placeholder `address`, `phone`, `email`, or WiFi `receipt_footer` (`[FAIL]`), and has non-empty store `address` and `phone` (`[WARN]`).
11. Receipt printer destination (`shop_settings.printer_name`) is configured (`[WARN]`).
12. Cache, queue, and session drivers are not set to `array` (`[WARN]`).
13. `SESSION_LIFETIME` is at least `480` minutes (`[WARN]` if below `480`).
14. `LOG_LEVEL` is not `debug` while `APP_ENV=production` (`[WARN]`).
15. PHP `zip` extension (`ZipArchive`) is loaded (`[FAIL]` if missing).
16. `mysqldump` binary is discoverable at `MYSQLDUMP_PATH` or on system `PATH` (`[WARN]` if not found).
17. `BACKUP_PATH` is outside the project directory or `BACKUP_COPY_PATH` is configured on a second drive (`[WARN]` if backups live only inside the project folder on the same disk).
18. Recent completed database backup (`backup.completed` in `activity_logs`) exists and is newer than `BACKUP_MAX_AGE_HOURS` (`[FAIL]` when `BACKUP_REQUIRED=true` in `production` and no backup has ever completed — clears automatically after the first successful `php artisan temupulang:backup`; `[WARN]` when older than `BACKUP_MAX_AGE_HOURS` or when `BACKUP_REQUIRED=false`).

All `[FAIL]` items must be resolved until `php artisan temupulang:preflight` exits with code `0`.

---

## 8. Thermal Receipt Printer & Automatic Print Worker Startup (Windows Task Scheduler)

### 8.1. Verify USB Thermal Printer Hardware
1. Confirm the Windows Print Spooler queue name in **Windows Settings -> Bluetooth & devices -> Printers & scanners** (e.g. `POS-80` or `POS80 Printer`).
2. Log in as Owner -> navigate to `/settings` -> set **Receipt Printer Name** (`printer_name`) to match the exact Windows printer queue name.
3. Run a hardware test print from PowerShell (creates no database records):
   ```powershell
   php artisan print:test --printer="POS-80"
   ```

### 8.2. Register `php artisan print:work` in Windows Task Scheduler
Configure a single-instance background worker that starts automatically whenever Windows boots:

1. Open **Task Scheduler** (`taskschd.msc`) -> **Create Task...**
2. **General Tab**:
   - **Name**: `TemuPulang Print Worker`
   - Select **Run whether user is logged on or not** and **Run with highest privileges**.
3. **Triggers Tab**:
   - **New...** -> **Begin the task**: `At startup` (optional second trigger: `At log on`).
4. **Actions Tab**:
   - **Action**: `Start a program`
   - **Program/script**: `C:\xampp\php\php.exe`
   - **Add arguments**: `artisan print:work --sleep=1`
   - **Start in**: `C:\xampp\htdocs\temu-pulang`
5. **Settings Tab**:
   - Uncheck **Stop the task if it runs longer than...**
   - Check **If the task fails, restart every: `1 minute`** (Attempt to restart up to `999` times).
   - **If the task is already running, then the following rule applies**: **`Do not start a new instance`** (critical to enforce single-worker execution alongside database claim-token fencing).

---

## 9. Automated Nightly Backup, Restore Verification & Disaster Recovery

> **CRITICAL SECURITY RULE — `.env` IS NEVER INCLUDED IN BACKUPS:**
> Backup zip archives (`temu_pulang_YYYYmmdd_HHMMSS.zip`) intentionally **never** contain `.env`, `APP_KEY`, or database passwords. You **must** keep a separate, offline copy of the production `.env` file (for example, in an encrypted password manager or a locked offline USB key in the owner's safe). Without `.env` (specifically `APP_KEY` and `DB_PASSWORD`), encrypted cookies/payloads cannot be decrypted.

### 9.1. Backup `.env` Keys (`config/backup.php`)
Configure these keys in `C:\xampp\htdocs\temu-pulang\.env` on the café Windows 11 host PC:

```dotenv
BACKUP_PATH="C:\backups\temu-pulang"
BACKUP_COPY_PATH="D:\temu-pulang-backups"
BACKUP_RETENTION_DAYS=14
MYSQLDUMP_PATH="C:\xampp\mysql\bin\mysqldump.exe"
MYSQL_CLIENT_PATH="C:\xampp\mysql\bin\mysql.exe"
BACKUP_MAX_AGE_HOURS=26
BACKUP_REQUIRED=true
```

- `BACKUP_PATH`: Primary backup folder on the host PC (outside `C:\xampp\htdocs\temu-pulang`).
- `BACKUP_COPY_PATH`: Secondary destination on a different physical drive, external USB drive, or cloud-synced folder (`D:\temu-pulang-backups`).
- `BACKUP_RETENTION_DAYS`: Deletes `temu_pulang_*.zip` files older than `14` days in both folders, while **always keeping the newest 3 backups** regardless of age.
- `MYSQLDUMP_PATH` & `MYSQL_CLIENT_PATH`: Full paths to XAMPP's `mysqldump.exe` and `mysql.exe`. The database password is passed exclusively via the child process's `MYSQL_PWD` environment variable and never appears on the command line or in logs.
- `BACKUP_MAX_AGE_HOURS`: Threshold (`26` hours) used by `temupulang:preflight` and the Owner Dashboard backup status banner.
- `BACKUP_REQUIRED=true`: Set to `true` on the café host PC so `php artisan temupulang:preflight` enforces that an initial backup has been completed before go-live (clears automatically after the first successful `php artisan temupulang:backup`).

### 9.2. Running a Manual Backup (`temupulang:backup`)
Run a verified backup at any time from PowerShell in `C:\xampp\htdocs\temu-pulang`:

```powershell
# Run full backup (primary BACKUP_PATH + secondary BACKUP_COPY_PATH + retention pruning)
php artisan temupulang:backup

# Skip secondary copy destination if external drive is temporarily detached
php artisan temupulang:backup --no-copy
```

Each run:
1. Executes `mysqldump --single-transaction --routines --triggers --no-tablespaces --default-character-set=utf8mb4` into a temporary `.sql` file.
2. Verifies the dump exists, exceeds the minimum byte size (`>= 1 KB`), contains at least one `CREATE TABLE`, and ends with `Dump completed`.
3. Packages `database.sql`, `manifest.txt` (timestamp, app name, DB name, table count, dump size — no secrets), and `qris/` (copied from `storage/app/public/qris`) into `temu_pulang_YYYYmmdd_HHMMSS.zip`, then deletes the temporary `.sql` file.
4. Copies the zip to `BACKUP_COPY_PATH` (logging a warning `backup.copy_failed` if the secondary drive is unavailable while still succeeding with exit code `0` if the primary zip is valid).
5. Prunes `temu_pulang_*.zip` files older than `BACKUP_RETENTION_DAYS` (always preserving the newest 3 backups) and records `backup.completed` in `activity_logs`.

### 9.3. Scheduling Nightly Backups in Windows Task Scheduler (`schtasks`)
The repository includes `scripts\backup-nightly.bat`, which changes to the project root, runs `php artisan temupulang:backup`, appends output to `storage\logs\backup.log`, and propagates the exit code.

Register the nightly scheduled task at `03:30 WIB` (after shift close, before the `06:00 WIB` business-day cutoff) from an elevated PowerShell or Command Prompt:

```powershell
schtasks /Create /SC DAILY /ST 03:30 /TN "TemuPulang Backup" /TR "C:\xampp\htdocs\temu-pulang\scripts\backup-nightly.bat" /RU "SYSTEM" /RL HIGHEST /F
```

> **Account Note:** Run the scheduled task under `SYSTEM` (or a dedicated local Windows Administrator account whose password does not expire and that has read/write access to both `BACKUP_PATH` and `BACKUP_COPY_PATH`) so the task continues running unattended when no user is logged into the desktop.

Test the scheduled task immediately and inspect `storage\logs\backup.log`:

```powershell
schtasks /Run /TN "TemuPulang Backup"
Get-Content "C:\xampp\htdocs\temu-pulang\storage\logs\backup.log" -Tail 25
```

### 9.4. Verifying a Backup Archive (`temupulang:backup-verify`)
Verify that the latest backup zip (or a specific file) can be cleanly extracted and imported into MySQL:

```powershell
# Verify the newest temu_pulang_*.zip in BACKUP_PATH
php artisan temupulang:backup-verify

# Or verify a specific backup zip file
php artisan temupulang:backup-verify --file="C:\backups\temu-pulang\temu_pulang_20261127_033000.zip"
```

`temupulang:backup-verify` extracts `database.sql` to a temporary folder, creates a scratch database named `temu_restore_check_<random>`, imports `database.sql` via `mysql.exe`, compares table counts and row counts of `orders`, `payment_transactions`, and `stock_movements` against the live database, and **always drops** the temporary `temu_restore_check_<random>` database and deletes the temporary folder in a `finally` block.

### 9.5. Step-by-Step Disaster Restore Procedure
If the Windows 11 host PC disk fails or the shop migrates to a replacement PC:

1. **Install Base Stack & Application Code**:
   - Install XAMPP (PHP 8.5, MySQL/MariaDB, Apache) on the replacement Windows 11 host PC.
   - Clone or copy the **Temu Pulang** application repository to `C:\xampp\htdocs\temu-pulang` and run:
     ```powershell
     cd C:\xampp\htdocs\temu-pulang
     composer install --no-dev --optimize-autoloader
     npm ci
     npm run build
     ```
2. **Create Empty Database & Local MySQL User**:
   - Start MySQL in XAMPP and create the empty `temu_pulang` database and local user:
     ```powershell
     & "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE temu_pulang CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'temupulang_user'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_DB_PASSWORD'; GRANT ALL PRIVILEGES ON temu_pulang.* TO 'temupulang_user'@'127.0.0.1'; FLUSH PRIVILEGES;"
     ```
3. **Restore `.env` from Separate Offline Secure Copy**:
   - Copy your separately stored production `.env` file to `C:\xampp\htdocs\temu-pulang\.env` and verify `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and printer/backup paths.
4. **Unzip the Backup Archive**:
   - Extract the newest verified `temu_pulang_YYYYmmdd_HHMMSS.zip` into a temporary folder (`C:\restore_tmp`):
     ```powershell
     Expand-Archive -Path "D:\temu-pulang-backups\temu_pulang_YYYYmmdd_HHMMSS.zip" -DestinationPath "C:\restore_tmp" -Force
     ```
5. **Import `database.sql` into `temu_pulang`**:
   ```powershell
   $env:MYSQL_PWD = "REPLACE_WITH_DB_PASSWORD"
   & "C:\xampp\mysql\bin\mysql.exe" --default-character-set=utf8mb4 --host=127.0.0.1 --port=3306 --user=temupulang_user temu_pulang -e "SOURCE C:/restore_tmp/database.sql;"
   Remove-Item Env:\MYSQL_PWD
   ```
6. **Restore `qris/` Uploaded Images**:
   ```powershell
   if (Test-Path "C:\restore_tmp\qris") {
       New-Item -ItemType Directory -Force -Path "C:\xampp\htdocs\temu-pulang\storage\app\public\qris" | Out-Null
       Copy-Item "C:\restore_tmp\qris\*" "C:\xampp\htdocs\temu-pulang\storage\app\public\qris\" -Recurse -Force
   }
   Remove-Item "C:\restore_tmp" -Recurse -Force
   ```
7. **Apply Any Newer Migrations, Link Storage, Clear Caches & Run Preflight**:
   ```powershell
   php artisan migrate --force
   php artisan storage:link
   php artisan config:clear
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan temupulang:preflight
   ```

### 9.6. Monthly Backup Maintenance Routine
On the **1st of every month** (and on rehearsal day before opening):
1. Run `php artisan temupulang:backup-verify` in PowerShell and confirm `[PASS]` with matching table counts.
2. Copy the verified `temu_pulang_YYYYmmdd_HHMMSS.zip` archive to an **off-site / separate physical device** (such as an external USB drive stored off-premises by the Owner).
3. Confirm the Owner Dashboard (`/dashboard`) displays the neutral green-dot `"Cadangan terakhir: <tanggal> (<ukuran>)"` / `"Last backup: <date time> (<size>)"` status banner with no failure notice.

---

## 10. Fixed LAN Address & Apache Production VirtualHost

> **Never use `php artisan serve` in production.** Built-in PHP development server is single-threaded and will hang when a cashier terminal loads assets while a print job or Livewire poll is running.

### 10.1. Static LAN IP & Windows Firewall
1. Assign a static local IPv4 address (or DHCP reservation on the shop router) to the Windows 11 host PC, e.g. `192.168.1.100`.
2. In **Windows Defender Firewall with Advanced Security**:
   - **Allow** inbound TCP port `80` (Apache HTTP) on the **Private / Local LAN** profile only.
   - **Block / Do NOT open** TCP port `3306` (MySQL/MariaDB). Keep MySQL bound to `bind-address = 127.0.0.1` in `C:\xampp\mysql\bin\my.ini`.

### 10.2. Apache VirtualHost Serving `public/`
In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, point `DocumentRoot` directly to `C:/xampp/htdocs/temu-pulang/public` so `.env`, `storage/`, and application source code are never web-accessible:

```apache
<VirtualHost *:80>
    ServerName 192.168.1.100
    DocumentRoot "C:/xampp/htdocs/temu-pulang/public"

    <Directory "C:/xampp/htdocs/temu-pulang/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "logs/temu-pulang-error.log"
    CustomLog "logs/temu-pulang-access.log" common
</VirtualHost>
```

Restart Apache from the XAMPP Control Panel or Windows Services (`net stop Apache2.4 && net start Apache2.4`) and verify that `http://192.168.1.100/login` loads cleanly from both the host PC and any tablet/phone on the shop Wi-Fi.

---

## 11. Rehearsal-Day Checklist (Before Opening Day)

Run through this end-to-end rehearsal on the physical hardware before the first real customer arrives:

- [ ] `php artisan temupulang:preflight` passes with `0 failed` checks.
- [ ] Log in as **Owner** at `http://192.168.1.100/login`:
  - [ ] Verify store name, address, phone, tax rate, service charge rate, receipt footer, and `printer_name` in `/settings`.
  - [ ] Upload the shop's real static QRIS image in `/settings` and verify the preview renders.
  - [ ] Verify real categories (`/menu/categories`), products and variants (`/menu/products`), modifiers (`/menu/modifiers`), ingredients (`/inventory/ingredients`), and BOM recipes (`/inventory/recipes`).
  - [ ] Enter initial physical stock counts via `/inventory/stock` (`Stock In` or `Counted` adjustment).
- [ ] Log in as **Cashier**:
  - [ ] Verify `/settings`, `/inventory/*`, `/menu/*`, `/printing/jobs`, and `/activity-logs` return `403 Forbidden`.
  - [ ] Open a shift at `/shifts` with a counted opening cash float.
  - [ ] Place a **Dine-in** order paid by **Cash** at `/pos` -> confirm receipt prints automatically via `print:work` and ingredient stock deducts in `/inventory/movements`.
  - [ ] Place a **Takeaway** order paid by **QRIS** at `/pos` -> confirm QRIS image displays in the checkout modal and receipt prints.
  - [ ] Record a manual **Cash In** and **Cash Out** at `/shifts` and test reversing one entry.
- [ ] As **Owner**:
  - [ ] Open the rehearsal order at `/orders/{order}/refund` and process a full refund with **Return ingredients to stock** checked -> verify `refund_credit` rows appear in `/inventory/movements`.
  - [ ] Close the rehearsal shift at `/shifts` and verify the expected cash calculation matches physical cash.
  - [ ] Test a nightly backup (`backup-nightly.ps1`) and confirm `temu_pulang.sql` is non-empty.

---

## 12. Downtime & Contingency Plan

If the Windows 11 host PC, power, or printer experiences an unexpected outage during service hours:

1. **Paper Order Pad & Printed Price Menu**:
   - Keep a carbon-copy paper order pad, calculator, pen, and laminated menu price sheet next to the cash drawer at all times.
   - Write each order sequentially on the pad with timestamp, items, variant/modifiers, order type (Dine-in / Takeaway), total IDR, and payment method (`Cash` or `QRIS`).
2. **Manual Receipts & Payment Collection**:
   - Keep a laminated printed copy of the store's static QRIS code at the counter so QRIS payments continue even if the screen is off.
   - Issue handwritten paper receipts to customers who request one.
3. **Recovery Once the Host PC Is Back Online**:
   - Either re-enter the paper orders into `/pos` under the active shift (so inventory deductions and order history stay complete) **or**, if back-entering individual orders is impractical, record the aggregated offline cash total as a manual **Cash In** movement at `/shifts` and document the paper slip numbers and offline QRIS total in the **Shift Closing Note** (`notes`).
   - If only the thermal printer failed while the POS remained online, payments and orders are already safely committed; check `/printing/jobs` as Owner to resolve `uncertain` jobs or retry `failed` jobs once the printer is reconnected.

---

## 13. First-Week Operational Routine

Perform these checks daily during the first week of live store operations:

1. **Inspect Application Logs Daily**:
   - Review `C:\xampp\htdocs\temu-pulang\storage\logs\laravel.log` at the end of each day for unexpected warnings or exceptions.
2. **Audit Inventory Deduction & Restock Failures**:
   - As Owner, open `/activity-logs`, filter by module `inventory`, and check for any `order_deduction_failed` or `refund_restock_failed` entries.
   - Run the recovery commands in **dry-run mode** (no flags) once per week or whenever a failure log is observed:
     ```powershell
     php artisan inventory:retry-deductions
     php artisan inventory:retry-restocks
     ```
   - If the dry-run lists affected payments or refunds that need recovery, apply them with:
     ```powershell
     php artisan inventory:retry-deductions --apply
     php artisan inventory:retry-restocks --apply
     ```
3. **Check Print Queue Health**:
   - Open `/printing/jobs` as Owner at shift close to confirm no print jobs are stuck in `pending`, `failed`, or `uncertain`.
4. **End-of-Week Physical Stock Opname**:
   - Count physical on-hand quantities for key ingredients (coffee beans, milk, syrups, cups) and post reconciliations in `/inventory/stock` using **Adjustment -> Counted Stock (`counted`)** mode with a clear reason (e.g. `Opname Mingguan Minggu-1`).

---

## 14. Essential Operational Notes for Store Staff

Brief all Owners and Cashiers on how inventory and refunds behave in Temu Pulang:

1. **Sales Are Never Blocked by Calculated Stock**:
   - Even if an ingredient's calculated balance in Temu Pulang is `0.000` or negative, `/pos` will **never** block a customer payment or checkout.
   - Ingredient balances are allowed to go negative (`balance_after < 0`) so a delayed stock-in entry or minor recipe variance never stops the cashier line. Negative or low balances surface as **Out / Low Stock** alerts for the Owner on `/dashboard` and `/inventory/ingredients`.
2. **Full Refunds vs. Partial Refunds (Inventory Restock Rule)**:
   - Ingredients can be automatically returned to stock (`refund_credit`) **only on a FULL refund** from the Owner refund screen (`/orders/{order}/refund`) when the **"Return ingredients to stock"** checkbox is ticked.
   - The checkbox defaults to **checked** when the order was still `paid` or `queued` (drinks not yet made), and defaults to **unchecked** when the order already reached `preparing`, `ready`, or `completed` (ingredients already consumed).
   - **Partial refunds never auto-restock** because the system cannot infer which specific item or ingredient remained unused. If a partial refund involves salvageable sealed items, the Owner must record a manual **Stock In** or **Stock Adjustment** at `/inventory/stock`.
