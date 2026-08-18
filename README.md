# Veterinary Consultation Backend

A role-based telehealth API for pet doctor consultations — patient/doctor/admin auth, doctor approval workflow, pet management, wallet/billing with per-second live-chat billing, and AJAX-polling chat.

Built as a 15-day guided backend project against a mentor-provided spec.

## Stack

- PHP 8.2 + MySQL (PDO)
- PHP Sessions (role-based auth: patient / doctor / admin)
- Vanilla JS (Fetch/AJAX) on the frontend
- XAMPP locally → Hostinger shared hosting in production

## Structure

- **`public/`** — web root (only browser-accessible folder), front-controller routing
- **`api/`** — endpoint handlers, not directly URL-reachable
  - `auth/` — login, logout
  - `patients/` — registration, profile, pets
  - `doctors/` — registration, list, availability, earnings
  - `wallet/` — details, recharge
  - `admin/` — doctor approval, settings
  - `chat/` — request, respond, messages, session, history
  - `support/` — public enquiries
- **`src/`** — shared logic
  - `auth/` — session helpers, role middleware, PIN hashing, email lookup
  - `wallet/` — atomic credit/debit service
  - `billing/` — server-side timing + per-second calculation
  - `validation/` — input and file-upload validation
- **`storage/`** — private uploaded files
  - `doctor_documents/` — served only through an authenticated script, never a direct URL
- **`config/`** — DB connection, environment config
- **`docs/`** — full day-by-day build log, see DEVLOG.md

## Running locally

1. Clone into `htdocs/` on XAMPP, start Apache + MySQL
2. Import `docs/vet_consult_db.sql` into a fresh database
3. Create `.env` with your DB credentials
4. Enable `mod_rewrite` and set `AllowOverride All` for this folder in `httpd.conf`
5. Health check: `GET /public/api/health`

## Documentation

Full day-by-day build log, schema decisions, endpoint-by-endpoint notes, and test coverage — see [`docs/DEVLOG.md`](docs/DEVLOG.md).