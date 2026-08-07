# Veterinary Doctor Consultation Backend — Documentation

## Files in this folder (Day 1)
- `user_flow.pdf` — user flow diagrams: patient/doctor/admin flows, plus state diagrams for doctor approval, chat/session lifecycle, and billing lifecycle. Source of truth for status/ENUM values used in the schema.
- `erd.pdf` — entity-relationship diagram (visual export of the schema)
- `schema.dbml` — full database schema in DBML format; paste into dbdiagram.io to visualize/edit
- `api_contract.pdf` — endpoint contract: paths, methods, auth levels, payloads
- `project_structure.pdf` — folder structure and routing architecture
- `role_permissions.pdf` — permission matrix across access levels (Guest, Patient, Doctor Pending, Doctor Approved, Admin)

## Schema Design Notes & Decisions (Day 2)

### Status/role fields — ENUM over VARCHAR
Columns use `ENUM(...)` instead of `VARCHAR` so MySQL rejects invalid values at the database level, not just in PHP:
- `users.role` — admin, doctor, patient
- `users.status` — active, inactive (mentor-confirmed; default is `inactive` — **open question**: no documented mechanism yet for how a user moves from inactive → active. Needs mentor clarification before building registration/login endpoints.)
- `doctor_profiles.approval_status` — pending, approved, rejected
- `doctor_approvals.decision` — approved, rejected
- `chat_requests.status` — pending, accepted, rejected
- `chat_sessions.status` — active, ended
- `billing_records.billing_status` — pending, finalized (supports idempotent finalize per T-14)
- `support_enquiries.status` — open, resolved

### `users` table
- Uses `pin_hash` — auth is PIN-based (see T-01: lockout after 5 failed attempts)
- `failed_attempts` (int, default 0) and `locked_until` (nullable timestamp) together implement the T-01 lockout mechanism
- `status` default flipped to `'inactive'` per mentor's confirmed schema — activation mechanism still undocumented, flagged above

### `wallet_accounts`
- Added `CHECK (balance >= 0)` — spec's Security/Validation column explicitly requires non-negative balance; DB-level safety net.

### `billing_records`
- `end_reason ENUM('manual', 'auto_low_balance')` added — not in the original field list, but needed to distinguish the two ways a session can end (Fig-1: Billing diagram shows `Manually_Ended` vs `Auto_Ended` as separate paths). Populated by whichever PHP code path triggers the end (manual end-session request vs. billing poll detecting low balance).
- `billing_status` supports the idempotency requirement in T-14 (end request may arrive twice, finalizes once)

### `doctor_earnings`
- No `status` column — mentor confirmed this isn't required (originally considered `ENUM('credited')` but no evidence in the spec supported more than one state, and mentor's schema omits it entirely)

### `admin_settings`
- `is_active BOOLEAN` — settings are kept as a history (new row per change, not updated in place), with only one row ever `is_active = TRUE`. Reading "current settings" = `WHERE is_active = TRUE`. Writing a new settings row must be paired with deactivating the old one in the same transaction — **not yet implemented in PHP**, this is a note for when BE-12 is built.

### `support_enquiries`
- `user_id` is nullable, with separate `name`/`email`/`contact` fields — BE-19 marks this endpoint "Public/Admin," meaning anonymous (non-logged-in) visitors can submit enquiries, so a hard `user_id` requirement would break that

### `pets`
- Uses `date_of_birth` instead of storing `age` directly — avoids age going stale; age can be calculated from DOB when needed

## Architecture

- **Routing:** Front-controller pattern. Only `public/` is Apache's web root; `public/index.php` reads the request path/method and `require`s the matching file from `api/`. Chosen over direct file access for stronger security — no file under `api/` is ever directly URL-reachable, reducing the risk of a forgotten auth check exposing an endpoint.
- **`.htaccess`** (in `public/`) rewrites all unmatched requests to `index.php`. Requires `AllowOverride All` in XAMPP's `httpd.conf` (default is `None`, must be changed manually) and `mod_rewrite` enabled.
- **`config/db.php`** — loads `.env`, connects via PDO (not mysqli, per spec's suggested stack) with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, and `EMULATE_PREPARES => false` for real prepared statements. No closing `?>` tag, to avoid stray-whitespace "headers already sent" bugs on files that get `require`d by every endpoint.
- **`.env`** (gitignored) holds DB credentials; never committed.

## Environment

- PHP 8.2 (XAMPP), MySQL via PDO, `pdo_mysql` extension confirmed enabled
- Local project root: `C:\xampp\htdocs\wingtrix_project`
- Health check: `GET http://localhost/wingtrix_project/public/api/health` — confirms both backend and DB connectivity

## Authentication & Role Security (Day 3)

### Updates to earlier days
- **`users.status` column removed.** Flagged in Day 2 notes as an open question (no documented activation mechanism). No BE endpoint in the contract sheet ever reads or writes it, so it was dropped via `ALTER TABLE users DROP COLUMN status;` rather than left as unused schema. Revisit if a future endpoint needs account-level activation.
- **Database renamed** — `pet_consult_db` → `vet_consult_db`, for consistency with the GitHub repo name (`veterinary-consultation-backend`). All 16 tables and existing data confirmed intact after the move. `.env`'s `DB_NAME` and this folder's schema export (now `vet_consult_db.sql`) updated to match.
- **`config/db.php`** now also sets `date_default_timezone_set('Asia/Kolkata')` at the top. PHP's `DateTime`/`time()` defaulted to UTC otherwise, producing `locked_until` timestamps hours off from the local system clock during lockout testing.

### Endpoints
- `POST /api/auth/login` — email + 4-digit PIN, returns `role` and `user` (`id`, `email`) on success, starts a PHP session
- `POST /api/auth/logout` — requires an active session; destroys it server-side and clears the session cookie

### PIN handling
- PINs are hashed with `password_hash()` (bcrypt via `PASSWORD_DEFAULT`), verified with `password_verify()` — never stored or compared in plaintext
- A 4-digit PIN has only 10,000 possible values, so hashing alone isn't sufficient — see lockout below

### Lockout (T-01)
- 5 consecutive failed PIN attempts on an existing account sets `locked_until` to now + 15 minutes and resets `failed_attempts` to 0
- A locked account is rejected before `password_verify()` even runs — checked first, so a correct PIN during a lockout window is still refused
- All failure cases (wrong PIN, nonexistent email, locked account, malformed input) return the identical generic response — `401 { "error": "Invalid credentials" }` for credential-related failures, `400 { "error": "Invalid request" }` for malformed input — so no response distinguishes *why* a login failed

### Sessions
- `session_regenerate_id(true)` is called immediately after successful login, to prevent session fixation
- Only `user_id` and `role` are stored in `$_SESSION` — never `pin_hash`
- Logout clears `$_SESSION`, calls `session_destroy()`, and explicitly expires the session cookie client-side

### Shared helpers (new in Day 3)
- `src/response.php` — `sendError($statusCode, $message)` / `sendSuccess($data, $statusCode = 200)`, used by every endpoint for consistent JSON response shape. `sendSuccess()` takes a raw array so different endpoints can return different shapes; it's not hardcoded to a fixed key like `message`.
- `src/auth/middleware.php` — `requireAuth()`, checks for a valid session (`$_SESSION['user_id']` set) and rejects with `401` if not. Resumes the session via `session_start()` internally. Role-specific checks (e.g. admin-only endpoints) are expected to build on top of this in a later day, not yet implemented.

### Testing
- Tested via Thunder Client (VS Code extension).
- Full login test matrix covered: correct login, wrong PIN, nonexistent email, malformed PIN, missing field, 5-attempt lockout, locked-account rejection of a correct PIN.
- Logout tested for: unauthenticated rejection, successful logout with an active session, and confirmed session invalidation (same session cookie rejected on reuse after logout).