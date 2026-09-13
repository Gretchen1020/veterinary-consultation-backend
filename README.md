# Pet Doctor Consultation Backend

PHP + MySQL backend for the Wingtrix **Veterinary / Pet Doctor Consultation** platform: role-based PIN login, doctor onboarding & approval, patient wallet, chat sessions, server-authoritative per-second billing, earnings, support enquiries, and in-app notifications.

**Internship package — Day 15 final documentation**

---

## Stack

| Layer | Choice |
|--------|--------|
| Runtime | PHP 8.2+ |
| Database | MySQL 8 (PDO, prepared statements) |
| Auth | Email + 4-digit PIN (bcrypt), PHP sessions |
| Layout | Front controller: `public/index.php` + `.htaccess` |
| Local | XAMPP (Windows) or equivalent |
| Deploy | Hostinger (or any Apache + PHP + MySQL) |

---

## Project layout (high level)

```
wingtrix_project/
  public/           # web root only
    index.php       # router
    .htaccess
  api/              # endpoint scripts (not directly URL-reachable)
  src/              # shared services (auth, wallet, billing, notifications, …)
  config/           # db.php loads .env
  storage/          # private doctor documents (outside public/)
  docs/             # schema, DEVLOG, diagrams
  .env              # local secrets (gitignored)
```

---

## Fresh setup (local XAMPP)

### 1. Copy project

Place the project under e.g. `C:\xampp\htdocs\vet_consult_project`.

### 2. Apache

- Enable `mod_rewrite`
- `AllowOverride All` for `htdocs` (so `public/.htaccess` works)

### 3. Database

1. Create database: `vet_consult_db`
2. Import **schema** SQL (`docs/vet_consult_db.sql` or latest dump)
3. Import **seed** (optional): `Day15_Final_Package/seed_sample_accounts.sql`

If your dump is older, apply Day 14 migrations as needed (`confirmed_at`, `notifications`, extended `end_reason`, `doctor_earnings.paid_at` / `paid_by`, etc.). Prefer a single up-to-date schema export from the machine that passed E2E.

### 4. Environment

```bash
cp Day15_Final_Package/.env.example .env
```

Edit `.env`:

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=vet_consult_db
DB_USER=root
DB_PASS=
```

### 5. Document storage

Ensure `storage/doctor_documents/` exists and is **writable** by PHP, and **not** under `public/`.

### 6. Health check

```
GET http://localhost/vet_consult_project/public/api/health
```

Expect JSON confirming API + DB connectivity.

### 7. Postman

1. Import collections from `E2E_Postman_Collections.zip` (flows A–I)
2. Import `E2E_Local.postman_environment.json`
3. Set `base_url` to `http://localhost/vet_consult_project/public`
4. Align pins/emails with seed accounts (default PIN **1234**)

---

## Sample accounts (seed)

| Role | Email | PIN |
|------|--------|-----|
| Admin | `admin@wingtrix.test` | `1234` |
| Patient | `patient1@test.com` | `1234` |
| Patient 2 | `patient2@test.com` | `1234` |
| Doctor (approved) | `dr.approved@test.com` | `1234` |
| Doctor (pending) | `dr.pending.e2e@test.com` | `1234` |

Seed also creates: one pet per patient, wallets (₹500 / ₹100), offline availability for approved doctor, active `admin_settings` (₹20/min, 25% commission, ₹50 minimum).

---

## Core API surface

Original contract **BE-01 … BE-19** plus mentor/integration endpoints (confirm, notifications, requests-list, dashboard-stats, earnings-summary, mark-paid, document stream, heartbeat, support resolve, health). See:

- `NEW_ENDPOINTS(not_in_spec).docx`
- `ADDITIONS(not_in_spec).docx`

Auth: session cookie after `POST /api/auth/login`. Role middleware on protected routes.

---

## Billing notes (important)

- Billing clock starts on **patient confirm**, not doctor accept
- Heartbeat / end require `confirmed_at`
- Stale sweep (CLI/cron): confirmed → `auto_timeout`; never confirmed → `unconfirmed_expired` (zero cost)
- Wallet never allowed to go negative; request blocked with **402** if balance &lt; minimum

Cron example (Hostinger):

```
php /home/<user>/…/src/billing/close_stale_session.php
```

Schedule every 1 minute (confirm absolute path on deploy).

---

## Testing artifacts

| Artifact | Purpose |
|----------|---------|
| `E2E_Testing/` / `E2E_Postman_Collections.zip` | Flows A–I |
| Day 14 test plan / screenshots / SQL | Confirm + notifications |
| `E2E-A` / `E2E-B` SQL verification docx | Per-flow DB proof |
| Assignment sheet T-01 … T-16 | Critical business rules |

---

## Hostinger (brief)

1. Upload project; set **document root** to `public/` (or `public_html` + copy of public front controller)
2. Create MySQL DB; import schema + seed
3. `.env` with Hostinger DB credentials
4. Writable `storage/doctor_documents`
5. Cron for stale session sweep
6. Postman `base_url` = `https://your-domain` (include `/public` only if that is still in the URL path)

---

## Submission checklist

See `SUBMISSION_CHECKLIST.md` in this folder (maps to Evaluation sheet items 1–10).

---

## Documentation index

| Doc | Content |
|-----|---------|
| `DEVLOG.md` | Day-by-day decisions, bugs, endpoints |
| `ADDITIONS(not_in_spec).docx` | Mentor-driven deltas vs original sheet |
| `NEW_ENDPOINTS(not_in_spec).docx` | Endpoints beyond BE-01…19 |
| `docs/schema.dbml` / SQL dump | Schema |

---

## Disclaimer

Sample PINs and emails are for **lab / demo only**. Change all credentials before any production use.
