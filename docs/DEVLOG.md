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

## Patient Registration & Pet Module (Day 4)

### Updates to earlier days
- **`pets.age` → `pets.date_of_birth`.** Flagged as a schema deviation back in Day 2 notes; the migration was carried out this day (`ALTER TABLE pets CHANGE age date_of_birth DATE;`) once registration actually needed to write to this column. Age (where needed by the frontend) must be computed from DOB at read-time, e.g. `TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE())`.
- **`requireAuth()` extended to accept an optional role.** Signature is now `requireAuth(?string $requiredRole = null)`. Existing no-argument calls (e.g. `logout.php`) are unaffected; endpoints can now additionally require a specific role (e.g. `requireAuth('patient')`), returning `403` for an authenticated-but-wrong-role request, vs. `401` for no session at all.
- **Route table extended** in `public/index.php`: `POST /api/patients/register`, `GET /api/patients/profile`, `POST /api/patients/profile`, `POST /api/patients/pets`.

### Endpoints
- `POST /api/patients/register` — public. Registers a new patient in a single transaction (`users` → `patient_profiles` → `pets`, chained via `lastInsertId()`), auto-logs in on success via `startUserSession()`. Returns `409` on duplicate email (checked via pre-transaction `SELECT`, with the `UNIQUE` constraint on `users.email` as a race-condition safety net), `400` on missing/malformed fields.
- `GET /api/patients/profile` — patient-only. Returns the logged-in patient's profile (via a `users`/`patient_profiles` join scoped to `$_SESSION['user_id']`) plus all their pets (`fetchAll()`, since a patient can have multiple).
- `POST /api/patients/profile` — patient-only. Updates `full_name`/`contact`, scoped to `$_SESSION['user_id']`. Existence pre-checked before the `UPDATE` (defensive — should be unreachable given registration's transaction guarantees, returns `500` if hit).
- `POST /api/patients/pets` — patient-only, separate endpoint from profile updates (deliberate design choice — pets and patient info are different tables with independent lifecycles, e.g. a patient can add/edit a pet without touching their contact info). Updates an existing pet's `name`, `type`, `breed`, `date_of_birth`, and optional `photo_path`.

### Shared helpers (new in Day 4)
- `src/validation/validation.php` — `checkRequiredFields(array $input, array $requiredFields): array` (collect-all missing fields, not fail-fast; treats missing key, `null`, and `''` as missing but *not* `0`/`false`, since those can be legitimate values), `isValidEmail()`, `isValidPin()`, `isValidDate(string $date, string $format = 'Y-m-d')` (uses `DateTime::createFromFormat()` with a round-trip string comparison — catches both malformed strings and non-existent calendar dates like Feb 30, which MySQL's non-strict mode would otherwise silently coerce to `0000-00-00` instead of rejecting).
- `src/auth/session.php` — `startUserSession(int $userId, string $role): void`, extracted from `login.php`'s inline session-setup code so both login and registration produce an identical logged-in session. Guards against double `session_start()` via `session_status()`.

### Ownership authorization (T-02)
Enforces **T-02** from the Business Rules & Tests sheet — a patient must never be able to read or modify another patient's data. No endpoint ever trusts a client-supplied ID to select which row gets read or written — every query is scoped server-side to `$_SESSION['user_id']`.
- `GET /api/patients/profile` derives the patient's own record via `WHERE users.id = ?` bound to the session's `user_id` — there is no `patient_id` parameter anywhere in the request, so a patient has no way to even attempt requesting someone else's profile.
- `POST /api/patients/profile` scopes its `UPDATE` the same way (`WHERE user_id = ?`), so a patient can only ever modify their own `full_name`/`contact`.
- Pet updates verify ownership via a `JOIN` through `patient_profiles` (`pets.patient_id = patient_profiles.id AND patient_profiles.user_id = ?`) *before* running the `UPDATE`. A nonexistent `pet_id` and a `pet_id` belonging to another patient both return the identical `403 { "error": "Request Denied" }` — the response never reveals which case occurred, to avoid leaking whether a given ID exists at all.
- Pet updates treat `photo_path` as optional: `SET photo_path = COALESCE(?, photo_path)` so omitting it in a request preserves the existing value rather than overwriting it with `NULL` (relevant since photo upload isn't built until Day 5).

### Routing edge case
- `PUT`/`DELETE` to `/api/patients/profile` currently return `404` from the router's fallback, not `405` from the endpoint file — `public/index.php`'s `$routes` array has no entry for those method/path combinations, so the request never reaches `profile.php`'s own `405` else-branch. Decided this is acceptable behavior (no route registered = `404`) rather than adding unused route entries just to surface a more semantically precise `405`.

### Testing
- Tested via Thunder Client. Full matrix covered for all four endpoints: happy path, duplicate email (`409`), missing fields (`400`), malformed email/pin/date (`400`, date validation isolated from the PIN-length check after an initial test-setup bug), unauthenticated access (`401`), method fallback (`405`/`404`).
- T-02 ownership violation tested concretely with two separate patient accounts: Patient A's session was used to attempt an update on Patient B's pet (`403`), then confirmed via Patient B's own `GET /api/patients/profile` that no change occurred.

### Deferred
- Explicit rollback-failure test for `register.php`'s transaction (approach: temporarily force a foreign key violation mid-transaction, confirm the earlier `users` insert is undone). Transaction logic already indirectly exercised once — a `date_of_birth`/`dob` column-name bug during development correctly triggered the `catch`/`rollBack()` path — but not yet captured as a dedicated, documented test.

## Doctor Registration & Document Upload (Day 5)

### Updates to earlier days
- **`src/auth/userslookup.php` added** — `emailExists($pdo, $email): bool`, extracted from `patients/register.php`'s previously-inline duplicate-email `SELECT`. `patients/register.php` refactored to call the shared helper instead of its own copy of the query (behavior confirmed unchanged via regression test — new email still registers, duplicate still `409`s).
- **Route table extended**: `POST /api/doctors/register`.

### Endpoints
- `POST /api/doctors/register` — public, `multipart/form-data`. Registers a new doctor across a three-table transaction (`users` → `doctor_profiles` → `doctor_documents` × 2), auto-sets `approval_status = 'pending'` (enforces T-03 — a pending doctor is blocked from dashboard/online APIs by role middleware once that check is built). Required text fields: `email`, `pin`, `full_name`, `degree`, `specialization`, `experience`, `contact`. Optional: `about`, `profile_photo`. Required files: `degree_certificate`, `id_proof`. Returns `409` on duplicate email, `400` on missing/malformed fields or failed file validation, `500` on upload or transaction failure.

### File upload handling
- **Three-layer file validation** (`validateUploadedFile()` in `src/validation/validation.php`): `$_FILES[...]['error'] === UPLOAD_ERR_OK` → size limit → real MIME type via `finfo_file()` read on `tmp_name` (not the client-supplied `type` field or filename extension, both of which are attacker-controlled labels, not verified content). Returns `['error' => string|null, 'mime_type' => string|null]`.
- **Size/type limits** (undocumented in the spec sheet — a design decision made and recorded here): documents (`degree_certificate`, `id_proof`) capped at 5MB, accepting `application/pdf`, `image/jpeg`, `image/png`; `profile_photo` capped at 2MB, image types only (no PDF — it's a photo, not a scanned document). Chosen in line with common KYC/document-upload conventions (typically 2–5MB for scans, 1–2MB for avatars).
- **Collision-safe storage** (`uploadFile()`, same file): permanent filenames use `bin2hex(random_bytes(16))` rather than the original client filename (prevents overwrite collisions and avoids embedding personally-identifying info like the doctor's email in a stored filename). Extension is derived from the *verified* MIME type via a `mimeToExtension` map, not the original filename's extension. Destination directory resolved via `realpath()` rather than a raw `__DIR__ . '/../../storage/...'` string — the raw version left `..` segments unresolved in the stored `file_path`, leaking the calling file's internal source location (`src/validation/..\..\storage\...`) into the database; `realpath()` produces a clean, canonical absolute path instead.
- **Private storage**: `storage/doctor_documents/` sits outside `public/`, so uploaded documents have no direct URL — retrieval requires a future authenticated admin-only script (T-04), not yet built (Day 6+).
- **Orphaned-file prevention**: every successfully-moved file's path is pushed into `$uploadedFilePaths` as it's uploaded. If a later file fails validation/upload, or the DB transaction itself fails, every previously-moved file in that request is `unlink()`'d before the error response is sent — no file is ever left on disk without a corresponding DB row, and no DB row is ever committed without its file (transaction rollback and file cleanup are ordered together in the `catch` block).

### Shared helpers (new in Day 5)
- `src/validation/validation.php` — `validateUploadedFile(string $fieldName, int $maxSize, array $allowedTypes, bool $isOptional): array` and `uploadFile(string $fieldName, string $destinationDir, string $newFileName, string $mimeType): array`, both added alongside the existing text-validation helpers.
- `src/auth/userslookup.php` — `emailExists($pdo, $email): bool` (see "Updates to earlier days" above).

### Infrastructure note — MySQL `sql_mode`
Local XAMPP MySQL was found running with a **non-strict** `sql_mode` (confirmed via `SELECT FIND_IN_SET('STRICT_TRANS_TABLES', @@sql_mode)` returning `0`) — meaning invalid data (e.g. a non-numeric string in an `int` column) is silently coerced (e.g. `"five"` → `0`) rather than rejected by the database. An attempt to enable `STRICT_TRANS_TABLES` locally coincided with an unrelated pre-existing MySQL system-table corruption (`proxies_priv`, Aria engine — same class of issue documented on Day 1–2) surfacing and blocking MySQL from starting; resolved by restoring XAMPP's clean backup system tables (`C:\xampp\mysql\backup\mysql`), which does not touch project data (confirmed `vet_consult_db` tables untouched throughout). Given the unrelated root cause, strict mode was **left disabled locally for now** — decision deferred, not necessarily final. Hostinger's production MySQL is expected to run in strict mode by default, so the behavior this would otherwise catch will be verified post-deployment instead.

### Testing
- Tested via Postman (Thunder Client's multipart file-upload support is paywalled on the free tier — switched tools mid-session).
- Full matrix covered: happy path with all fields/files (`200`), happy path with optional `profile_photo` omitted (`200`, confirmed `NULL` in DB and only 2 `doctor_documents` rows), missing required text field (`400`), invalid email format (`400`), invalid PIN format (`400`), duplicate email (`409`), missing required file (`400`, exact field named in message), oversized file (`400`, confirms size check fires before type check), disguised file type — `.txt` renamed to `.pdf` (`400`, confirms `finfo_file()` rejects based on real content regardless of extension/label). Wrong HTTP method returns `404` from the router's fallback rather than `405` from the endpoint's own guard clause, since `public/index.php`'s route table has no `GET` entry for this path — same pattern as the Day 4 routing edge case; endpoint-level guard clause kept as defense-in-depth despite currently being unreachable.

### Deferred
- Transaction-rollback file-cleanup path (`unlink()` on a genuine mid-transaction `PDOException`) not empirically verified for either doctor or patient registration — blocked locally by non-strict `sql_mode` (see infrastructure note above). Code logic reasoned through carefully and believed correct; will be verified end-to-end once deployed to Hostinger's strict-mode MySQL.
- File size/type limits and the `experience`/`contact` PHP-level validation added this session (`isValidExperience()`, `isValidContact()`) were prompted by discovering the non-strict-mode data-coercion risk; worth auditing other numeric/length-constrained fields project-wide for the same gap.

## Admin Doctor Approval Workflow (Day 6)

### Updates to earlier days
- **Path-depth bug in `require_once` chains.** All four new files live at `api/admin/doctors/` — three directory levels deep — but were initially written with the same `../../` relative-path prefix used by two-levels-deep files like `api/patients/profile.php`. This resolved one level short of the project root (landing at `api/` instead), producing a fatal `require_once` failure on first request. Fixed to `../../../` across all four files. Same class of bug flagged as a recurring risk in earlier sessions (Day 4/5 `$basePath` issues) — worth a dedicated check whenever a new file's directory depth changes.
- **Route table extended**: `GET /api/admin/doctors/pending.php`, `GET /api/admin/doctors/detail.php`, `GET /api/admin/doctors/document.php`, `POST /api/admin/doctors/update-status.php`.

### Endpoints
- `GET /api/admin/doctors/pending.php` (BE-10) — admin-only. Returns a lean list of doctors with `approval_status = 'pending'`: `doctor_id` and `full_name` only. Deliberately excludes `email`, `contact`, and other profile fields (per mentor guidance, given after an initial back-and-forth on how much a list view actually needs) — those are reserved for `detail.php`.
- `GET /api/admin/doctors/detail.php` — admin-only, not present in the API contract by name (the Day 6 task description calls out "application detail" as a separate deliverable from BE-10's list, without assigning it a BE-XX number). Takes `doctor_id` via query string, returns the full `doctor_profiles` row (joined with `users` for `email`) plus a nested `documents` array (`document_id`, `document_type` per file — no `file_path`, no `mime_type`, no `original_name`).
- `GET /api/admin/doctors/document.php` — admin-only. Takes `document_id` via query string (necessarily a `GET` param, not a POST body, since the frontend consumes this as an `<img src>`/link target, which can't carry a body). Looks up `file_path` and `mime_type` from `doctor_documents`, sets `Content-Type` from the stored MIME type, and streams the file via `readfile()` instead of `sendJson()`. This is the only endpoint in the project so far that returns raw bytes rather than a JSON envelope.
- `POST /api/admin/doctors/update-status.php` (BE-11) — admin-only. Approves or rejects a pending doctor with a required `remark`, in a single transaction that updates `doctor_profiles.approval_status` and inserts an audit row into `doctor_approvals` (`doctor_id`, `admin_id` from `$_SESSION['user_id']`, `decision`, `remark`, `decided_at`).

### Document access design (T-04)
`storage/doctor_documents/` remains outside `public/` (per Day 5's design), so no document file has ever been directly URL-reachable. `document.php` is the sole access path, and it enforces `requireAuth('admin')` *before* the database is ever queried — a `document_id` is not a secret (same reasoning as `pet_id` in Day 4: the number being guessable doesn't matter, since the endpoint refuses to act on it without passing authorization first). Verified: a non-admin session requesting a known-valid `document_id` gets `403` with no file bytes in the response body at all.

### Concurrency handling on approve/reject
`update-status.php` uses `SELECT ... FOR UPDATE` inside the transaction to lock the target doctor's row before checking `approval_status`, closing a race condition where two admins (or one admin with two open tabs) could both act on the same pending doctor simultaneously. Any doctor not currently `pending` — already `approved` or `rejected` — returns `409 Conflict`, distinct from `400` (bad input) or `404` (doesn't exist): the resource exists and the request is well-formed, but its current state doesn't permit the action.

### Rejected-doctor reconsideration — checked against spec, not assumed
Raised the question of whether a rejected doctor could be flipped straight to `approved` by an admin re-clicking on the same row. Checked `user_flow.pdf`'s doctor flow rather than guessing: step 4 states a rejected doctor resubmits their application, which returns them to `pending` — an admin does not directly reverse a rejection. `update-status.php`'s `pending`-only guard is therefore correct as-is; a doctor resubmission endpoint doing rejected → pending is a separate, currently-unbuilt piece (see Open Items).

### Response shape — verified against contract wording, not assumed
BE-11's contract entry specifies the response as *"updated approval state."* Initially drafted with an extra `message` field by habit (matching the shape of other endpoints); removed after checking the literal contract text — final shape is `{ doctor_id, approval_status, remark }`. Similarly considered and deliberately excluded `admin_id` from the response — it's persisted in `doctor_approvals` for the audit trail, but has no frontend consumer in this response (the acting admin already knows their own identity from their own session).

### Testing
- Built a dedicated test plan document (`Day6_Test_Plan.docx`, same format as the Day 5 test matrix) — 29 cases across all four endpoints, covering happy paths, auth/role checks (`401`/`403`), validation (`400`), not-found (`404`), method guards (`405`), and the state-conflict case (`409`).
- Two cases flagged critical: T-04 (non-admin direct request to `document.php` → `403`, confirmed no file bytes leak into the error response) and the concurrent double-decision test on `update-status.php` (two near-simultaneous requests against the same pending doctor — confirmed exactly one succeeds and the `FOR UPDATE` lock, not just the `pending` check alone, is what prevents the second).
- Not yet run against a seeded admin account — `users` has no self-registration path for the `admin` role (per `role_permissions.pdf`), so an admin row must be inserted directly. A one-off seed script (`password_hash()`'d PIN, matching the existing bcrypt scheme from Day 3) was written for local use only; not committed.

### Bugs caught during build/review
- `pending.php` initially used `$stmt->fetch()` instead of `fetchAll()` — would have silently returned only one of potentially several pending doctors.
- `pending.php` initially treated a zero-result pending queue as `500 Profile not found` (copied from `profile.php`'s single-row existence check) — fixed to return a normal `200` with an empty array, since "nothing pending" is expected steady-state, not an error.
- All four files' `require_once` paths (see "Updates to earlier days" above).

### Open items flagged for mentor
1. `?status=` filter on `pending.php`, to let admin view approved/rejected doctors alongside pending — considered and explicitly deferred, since BE-10's contract entry only specifies pending applications; would be scope creep to build unprompted.
2. Doctor resubmit-after-rejection endpoint — described narratively in `user_flow.pdf` (rejected → resubmit → pending), but has no corresponding BE-XX entry in the API contract sheet.

## Doctor Availability & Directory (Day 7)

### Updates to earlier days
- **Route table extended**: `GET /api/doctors/list.php`, `POST /api/doctors/availability.php`, `GET /api/doctors/heartbeat.php`. All three files live at `api/doctors/` — two directory levels deep, same as `api/patients/*.php` — so the existing `../../` `require_once` prefix pattern applied directly; checked explicitly against the Day 6 path-depth bug before writing any includes, no fix needed this time.

### Endpoints
- `POST /api/doctors/availability.php` (BE-07) — approved-doctor-only. Toggles `is_online` via an upsert (`INSERT ... ON DUPLICATE KEY UPDATE`, relying on the existing `UNIQUE` constraint on `doctor_id`). `doctor_id` is always resolved server-side from `$_SESSION['user_id']` → `doctor_profiles.user_id`, never trusted from the request payload — same ownership-scoping pattern used since Day 4. Every call stamps `last_seen_at = NOW()` regardless of the `is_online` value being set to `true` or `false`.
- `GET /api/doctors/list.php` (BE-06) — public. Returns approved doctors only; `approval_status = 'approved'` is applied unconditionally before any online/offline logic, so rejected and pending doctors are excluded from the directory regardless of their `doctor_availability` state (enforces T-05). Supports `search` (partial name match), `specialization` (exact match), and `online` (boolean filter) query params.
- `GET /api/doctors/heartbeat.php` — no BE-XX number assigned to this one (see Open items below). Approved-doctor-only. Refreshes `last_seen_at` only — never writes to `is_online`. Returns `409` if no `doctor_availability` row exists yet for the calling doctor, rather than silently creating one; heartbeat is meant to refresh an existing state, not originate one.

### Staleness handling design (T-05)
A 5-minute window (`INTERVAL 5 MINUTE`) determines whether an `is_online = 1` doctor is still actually live. Rather than filtering staleness out at read-time only, `list.php` runs a lazy-write pass before building its response: any doctor whose `last_seen_at` has exceeded the window gets `is_online` forced to `0` in the DB, not just hidden from that response. Considered a simpler read-only-filter alternative (leave `is_online` untouched, just exclude stale doctors from the current query) but chose the lazy-write so the stored value stays consistent for any other future read of the table, at the cost of a write happening inside a nominally read-only `GET` endpoint. Verified via manual `last_seen_at` backdating in phpMyAdmin (real-time testing isn't practical for a 5-minute window) that the stored `is_online` value — not just the JSON response — actually flips.

### Heartbeat as a contract addition
`heartbeat.php` is not present in the API contract's BE-06/BE-07 entries. Added because the staleness mechanism above needs some way for `last_seen_at` to refresh without forcing a full online/offline toggle call each time — folding it into `availability.php` was considered and rejected, since that would conflate "doctor's stated intent" with "doctor is still connected," two different signals. Flagged for mentor review (see Open items).

### Testing
- Built a full Postman collection (`Day7_Doctor_Availability_Directory.postman_collection.json`) covering all 30 cases across the three endpoints, including T-03 (pending/rejected doctor lockout on both `availability.php` and `heartbeat.php`) and T-05 (staleness force-offline, staleness boundary, rejected-doctor exclusion with and without the `online` filter).
- 
### Open items flagged for mentor
1. `heartbeat.php` — added as an unnumbered endpoint beyond BE-06/BE-07. Open question: should this get a formal BE-XX number, or is it considered internal supporting infrastructure for the staleness mechanism described elsewhere in the plan?
2. Lazy-write inside `list.php` (a `GET` endpoint performing a DB write) — minor REST purity deviation, noted for awareness rather than as an unresolved question; believed correct given T-05's requirements.

## Wallet & Transaction Ledger (Day 8)

### Updates to earlier days
- **Route table extended**: `GET /api/wallet/details.php`, `POST /api/wallet/recharge.php`. Both live at `api/wallet/` — two directory levels deep, same as `api/patients/*.php` and `api/doctors/*.php` — so the existing `../../` `require_once` prefix applied directly; 
- `patient_profiles.id` resolution (see below) is extracted into a shared helper this session, but `pets.php` and `profile.php` (Day 4) still perform the same lookup inline via their own `JOIN`s. Not migrated to the new helper — that would mean touching already-working Day 4 code outside this session's scope. Flagged under Open items.

### Endpoints
- `GET /api/wallet/details.php` (BE-08) — patient-only. Returns balance and transaction history for the logged-in patient's wallet. Supports an optional `?limit=` query param (default 20, clamped 1–100) — not specified in the contract, added since returning a patient's entire transaction history unbounded isn't reasonable once a wallet has months of activity.
- `POST /api/wallet/recharge.php` (BE-09) — patient or admin. A patient recharges only their own wallet (no `patient_id` accepted in the body at all — any presence of it is treated as a mismatched/suspicious request and rejected outright, rather than trying to compare it against the session in an unclear ID space). An admin must supply a target `patient_id` and can recharge any patient's wallet. The contract's third listed audience, "Test," is read as `mode="test"` being an accepted recharge mode value, not a separate auth role — the project's role enum has only patient/doctor/admin.

### Wallet locking & idempotency design (T-06, T-07)
- `src/wallet/wallet_service.php` centralizes all balance mutation behind `walletCredit()`/`walletDebit()`, both requiring the caller to pass `$pdo` rather than opening their own connection — endpoints stay responsible for the DB connection and HTTP response, the service stays pure logic.
- Row locking via `SELECT ... FOR UPDATE` inside an explicit transaction, same pattern as the Day 6 doctor-approval race-condition fix, so two concurrent recharge/debit calls against the same wallet can't both read a stale balance (T-06).
- Duplicate-reference protection (T-07) is two-layered: an app-level `SELECT` for an existing `(reference_type, reference_id)` pair before the locked update runs, plus a DB-level `UNIQUE(reference_type, reference_id)` index added as a migration this session — the app-level check alone can't fully close the race between two truly simultaneous identical requests, since both could pass the check before either commits. A caught duplicate returns the *original* transaction's effect (same `balance_after`, `duplicate: true`) rather than an error — recharge is meant to be safely retryable by a client that didn't get a response the first time.
- `walletDebit()` has no caller yet — built this session anyway so Day 12's billing engine reuses this locking/idempotency pattern instead of inventing a second one.

### Schema migration
`wallet_transactions.reference_id` widened from `int(11)` to `varchar(100)` — the original schema assumed a numeric reference, but real recharge references (gateway transaction IDs, or even test-mode identifiers) are naturally alphanumeric strings. Migration paired with the `UNIQUE(reference_type, reference_id)` index mentioned above, applied in the same `ALTER TABLE` pass.

### `patient_id` resolution — `patient_profiles.id` vs `users.id`
`wallet_accounts.patient_id` (like `pets.patient_id`) references `patient_profiles.id`, not `users.id` directly — confirmed against `pets.php`'s existing ownership-check `JOIN` (`... JOIN patient_profiles ON pets.patient_id = patient_profiles.id WHERE ... AND patient_profiles.user_id = ?`) and the Database Design spec sheet. `$_SESSION['user_id']` is always `users.id`, so both wallet endpoints resolve the patient's own request via `SELECT id FROM patient_profiles WHERE user_id = ?` before touching `wallet_accounts`. Extracted into `src/auth/patient_profile.php`.

For the admin-initiated recharge path, the client-supplied `patient_id` in the request body is used directly as-is. An existence check (`SELECT id FROM patient_profiles WHERE id = ?`) was added before crediting, returning `404` if the supplied ID doesn't correspond to a real patient — without it, `getOrCreateWallet()` would silently create a wallet for any integer an admin sent, with no error at all.

### Shared helpers (new in Day 8)
- `src/wallet/wallet_service.php` — `getOrCreateWallet()`, `walletCredit()`, `walletDebit()`, `InsufficientBalanceException`, `DuplicateReferenceException`.
- `src/validation/validation.php` — `isValidAmount()` and `isValidEnum()` added alongside the existing text-validation helpers. `isValidEnum()` deliberately generic (value + allowed-list) rather than a one-off `isValidMode()`, since Day 12's billing status/end-reason fields are known to need the same shape.
- `src/auth/patient_profile.php` — `getPatientProfileId()` is newly added to lookup patients corresponding to passed user id i.e, patient id resolution from users id.

### Testing
- Postman collection (`Day8_Wallet_Postman_Collection.postman_collection.json`) — 19 requests across 9 folders, session-order-dependent (PHP cookie auth). Includes a dedicated folder documenting T-06 concurrency as a manual two-terminal `curl` test rather than a real Postman request — sequential request execution can't produce genuinely simultaneous calls, and `walletDebit()` has no real caller yet to test against until Day 12.
- Test plan (`Day8_Test_Plan.docx`) — 18 cases, same landscape six-column format as Day 5/6, priority-tiered (Critical/High/Medium/Manual) by row shading.
- SQL verification queries (`Day8_Verification_Queries.sql`), mapped to test plan rows, plus four general integrity checks run independent of any specific test case: balance never negative, ledger reconciliation (computed sum of `wallet_transactions` credits/debits vs. the stored `wallet_accounts.balance`, to catch a mutation that updated one without the other), structural duplicate-reference check, and migration-applied verification (run first, to rule out "test failed because the migration wasn't applied" before debugging anything else).

### Bugs caught during build/review
- Column name mismatch: code initially used `type`, actual `wallet_transactions` column is `transaction_type` — caught in both the `INSERT` statements (`wallet_service.php`) and a `SELECT` (`details.php`) that was missed on the first pass and only caught later.
- Redundant `exit;` statements following every `sendError()` call — `sendError()` already exits internally (confirmed against `response.php`), so these were dead code. Removed 10 total to match the established convention already followed.

### Deferred
- T-06 true concurrent-debit testing — see Testing above. Revisit once `walletDebit()` has a real caller (Day 12 billing).

### Open items flagged for mentor
1. `pets.php` and `profile.php` (Day 4) still resolve `patient_profiles.id` inline via their own `JOIN`s rather than the pattern settled on this session — not a bug, just three slightly different versions of the same lookup existing in the codebase. Worth a consistency pass, not urgent.
2. Admin-supplied `patient_id` in `recharge.php`'s request body is assumed to already be a `patient_profiles.id`. Worth confirming this is what the eventual admin UI will actually send once it's built — if it lists patients by `users.id` instead, recharges would either 404 against the new existence check or, worse, silently target the wrong wallet if the ID happened to collide with a real `patient_profiles.id`.

## Admin Settings & Pricing (Day 9)

### Updates to earlier days
- **Route table extended**: `GET /api/admin/settings`, `POST /api/admin/settings`.

### Endpoints
- `GET /api/admin/settings` (BE-12) — admin-only. Returns the current active settings row (`is_active = 1`) plus the full version history (`ORDER BY created_at DESC`), so a change's timeline is auditable rather than only exposing the latest value.
- `POST /api/admin/settings` (BE-12) — admin-only. Creates a new settings version rather than updating in place: deactivates whatever row is currently `is_active = 1`, then inserts the new row as active, both inside a single transaction. Matches the versioned-history design already noted in the Day 2 schema notes for this table (`admin_settings` — "settings are kept as a history... not yet implemented in PHP" — implemented this session).

### Validation design
- `rate_per_minute` reuses the existing `isValidAmount()` (`> 0`) unchanged.
- `commission_percent` and `minimum_balance` do **not** reuse `isValidAmount()` — both have legitimate `0` values (commission-free tier, no minimum floor), and `isValidAmount()` requires strictly `> 0`. Written as inline range checks instead (`0–100` and `>= 0` respectively) rather than modifying the shared helper's existing behavior, since other callers (`wallet/recharge.php`) depend on the strict `> 0` semantics.
- `currency` uses the existing `isValidEnum()` against an allowed-list array defined in `settings.php` — **placeholder value `['INR', 'USD']`**, not yet confirmed against real business requirements (see Open items).

### Infrastructure incident — `users` table InnoDB corruption
Mid-session, seeding the first `admin_settings` row surfaced a pre-existing, unrelated problem: `users` returned MySQL error `#1932 (Table 'vet_consult_db.users' doesn't exist in engine)` on every query, despite appearing normally in `SHOW TABLES`. Diagnosis ruled out a stale InnoDB dictionary cache (survived a clean MySQL restart) and an `innodb_force_recovery` misconfiguration found in `my.ini` (removed, but didn't resolve it either) before `CHECK TABLE users` confirmed a genuine tablespace-level mismatch — `users.ibd` was found at ~2.1MB versus ~80–100KB for every comparable table, consistent with a corrupted/mismatched tablespace rather than a simple missing file.
Resolved by restoring `users.frm`/`users.ibd` from an existing local `data_backup_2026-08-22` folder (predating the corruption), swapped in while MySQL was stopped. No production/Hostinger impact — local XAMPP only. Root cause not conclusively identified; flagged for monitoring (see Open items) rather than closed.

### Testing
- Test plan (`Day9_Test_Plan_BE12_Admin_Settings.docx`) — 14 cases, same landscape six-column format as Days 5/6/8: happy-path GET/POST, auth/role checks (`401`/`403`), all four validation rules independently, malformed JSON body, missing required field, wrong HTTP method (`405`), and a dedicated versioning-integrity case (three sequential POSTs, confirming exactly one `is_active = 1` row after each).
- Postman collection (`postman_collection_day9.json`) — requests tagged `[settings.php #1]`–`[settings.php #14]`, matching the test plan numbering 1:1.
- SQL proof queries (`sql_proof_queries_day9.sql`) — grouped by which test case(s) each verifies; uses `/* */` block comments throughout per established convention (`--` line comments break on phpMyAdmin copy-paste).

### Open items flagged for mentor
1. **Currency allowed-list is a placeholder** (`['INR', 'USD']`) — needs the real supported-currency set before this is production-correct; affects both `settings.php`'s validation and the seed row.
2. **`users` corruption root cause unresolved** — fixed via backup restore, but *why* `users.ibd` grew to ~20x its expected size with no reported crash is still unknown. Worth keeping an eye on (possible antivirus/sync-tool interference with the XAMPP data folder, or a second MySQL process contending for the same files) in case it recurs.
3. Doctor-specific rate override — the 15-Day Plan's Day 9 description mentions "default or doctor-specific rate" as a possible variant; the actual `admin_settings` schema only supports a single global rate, no per-doctor field. Confirmed as fresh/global-only for this session; per-doctor override treated as out of scope pending mentor discussion, same pattern as the resubmit-after-rejection item from Day 6.

## Chat Request & Session Lifecycle (Day 10)

### Updates to earlier days
- **`src/auth/doctor_profile.php` added** — `getDoctorProfileId(PDO $pdo, int $userId): ?int`, mirroring `src/auth/patient_profile.php`'s identity-resolution pattern exactly (Day 8). No doctor-side equivalent existed before this session; every prior doctor endpoint (`availability.php`, `heartbeat.php`, `update-status.php`) either resolved doctor identity inline or didn't need it. First real second-use-case for this lookup shape, so it was extracted as a shared helper rather than written inline again.
- **Route table extended**: `POST /api/chat/request`, `POST /api/chat/respond`. Both live at `api/chat/`.

### Endpoints
- `POST /api/chat/request` (BE-13) — patient-only. Validates the target pet belongs to the logged-in patient (same ownership-`JOIN` pattern as Day 4's pet updates), confirms the doctor exists with `approval_status = 'approved'` AND `doctor_availability.is_online = 1`, blocks a duplicate `pending` request against the same doctor+pet pair, then inserts `chat_requests` with `status = 'pending'`.
- `POST /api/chat/respond` (BE-14) — doctor-only. Row-locks the target request (`SELECT ... FOR UPDATE`, same race-prevention pattern as Day 6's `update-status.php`), enforces that only the doctor the request was addressed to may act on it (T-09), and rejects if the request isn't currently `pending` (`409`, same "well-formed request, wrong resource state" semantics as Day 6's approval-conflict case). On `reject`: updates `chat_requests.status`. On `accept`: same transaction updates `chat_requests.status` **and** inserts the `chat_sessions` row (`status = 'active'`, `rate_per_minute` pulled from the currently-active `admin_settings` row per Day 9's versioned-settings design) — both writes commit together or not at all.

### Schema/FK confirmation
`chat_requests.doctor_id` confirmed to reference `doctor_profiles.id`, not `doctor_approvals.id` — consistent with the `doctor_documents.doctor_id → doctor_profiles.id` convention established in Day 5. `doctor_approvals` is an audit-log table (one row per decision, potentially multiple per doctor across a reject→resubmit→approve cycle per Day 6's flow notes), so it was never a candidate for a stable FK target.

### `chat_sessions` denormalization — considered and declined
`user_flow.pdf`'s Fig-1 (Chat Session state diagram) implies participant identity might get copied onto `chat_sessions` at accept-time for fast lookup during message polling. Evaluated against the actual `chat_sessions` schema (`id, request_id, status, started_at, ended_at, rate_per_minute` — no `patient_id`/`doctor_id` columns) and decided **not** to alter the table this session: participant checks join through `chat_requests` via the indexed `request_id` FK, which is a single cheap join, not a real cost yet. Revisit only if Day 11's message-polling endpoint shows measurable cost from this join under real load — not a decision to make preemptively on a schema that's already live.

### Business-rule addition beyond the contract
The duplicate-pending-request guard in `request.php` (a patient can't stack multiple pending requests to the same doctor for the same pet) isn't explicit in BE-13's contract entry or in the Business Rules & Tests sheet. Added because the state diagram's single `RequestPending` state per doctor-patient-pet triple implied it, but flagged for mentor discussion rather than treated as settled (see Open items).

### Testing
- Postman collection (`Day10_Postman_Collection.json`) — 22 requests: a 6-request `Setup` folder (login as patient / target doctor / second doctor for the T-09 case, doctor online/offline toggles, logout) plus 8 tests each for `request.php` and `respond.php`, tagged `[request.php #13]` / `[respond.php #14]` matching established convention. `request_id` auto-captured into a collection variable via `respond.php` Test 1's test script so it threads through the rest of the folder without manual copying. All bodies explicitly set to `options.raw.language: "json"`.
- SQL proof queries (`Day10_SQL_Proof_Queries.sql`) — 10 queries: two general recent-rows checks, and one targeted query per test case with a specific data outcome to verify (duplicate-pending count staying at 1, accept-transaction integrity via a `chat_requests`/`chat_sessions` join, reject leaving zero sessions, T-09 non-mutation, idempotency on an already-responded request, and an `admin_settings` cross-check confirming the rate actually used matches the active row). `/* */` block comments throughout per established convention.

### Open items flagged for mentor
1. **Duplicate-pending-request guard** (`request.php`) — not explicit in BE-13's contract or the Business Rules & Tests sheet; added based on the state diagram's implied one-request-per-doctor-patient-pet-at-a-time model. Confirm this is the intended behavior rather than an unrequested restriction.
2. **Doctor resubmit-after-rejection endpoint** — still open from Day 6, now more directly relevant since `chat_requests`/`respond.php` assumes a doctor's `approval_status` is a stable `approved` at request-time; a resubmission flow would affect this indirectly if a doctor's status can later change.
3. **`chat_sessions` denormalization** — see design note above; not an open question requiring an answer now, but flagged as a decision point to revisit once Day 11 message-polling volume is real rather than theoretical.

## Chat Messages & Polling (Day 11)

### Updates to earlier days
- **`getAuthorizedSession()` extracted to `src/chat/session_helpers.php`.** Originally written inline in `messages.php` (used twice, once per HTTP method branch), then pulled out once a second *file*-level caller became concrete — BE-16's `session.php` (Day 12) will need the identical participant/status check against `chat_sessions`. Added an optional `$requireActive = true` parameter ahead of that Day 12 need — `session.php`'s eventual end-session logic will need to read a session that's about to become (or just became) non-`'active'`, which Day 11's strict default would otherwise block. Unused by anything yet; flagged as a preemptive addition, not a confirmed requirement.
- **Route table extended**: `GET /api/chat/messages`, `POST /api/chat/messages` — both routed to the same `api/chat/messages.php`, which branches internally on `$_SERVER['REQUEST_METHOD']`, matching BE-15's contract of one file handling both verbs.
- **Status-code convention confirmed and aligned.** Initial draft used `422` for all bad-input cases (missing fields, empty/oversized message, missing `session_id`). Checked against `respond.php`'s actual code (`sendError(400, ...)` for both missing-fields and invalid-enum-value cases) rather than guessing — `400` is the established convention project-wide, not `422`. All five bad-input `sendError()` calls in `messages.php` corrected to `400`; `403`/`404`/`405`/`409` left as-is (genuine HTTP-semantic codes, not "bad input").

### Endpoints
- `GET / POST /api/chat/messages.php` (BE-15) — session-participant-only (patient or doctor; `requireAuth()` carries no role restriction, since either role is legitimate here — participation is checked per-session, not per-role, via `getAuthorizedSession()`).
  - **GET (poll):** `session_id` (required) + `last_message_id` (optional, default `0`). Returns messages with `id > last_message_id`, ascending, capped at 50 per response (`MESSAGE_PAGE_SIZE`), plus a `last_message_id` bookmark for the client's next poll — advances to the last returned id, or echoes the input unchanged on an empty result so the bookmark never regresses.
  - **POST (send):** `session_id` + `message`. Trims and rejects blank/oversized input (`MAX_MESSAGE_LENGTH`, T-10) before touching the database. Re-selects the inserted row rather than trusting the submitted payload, so the response reflects real DB values (`sent_at`, `id`). Returns `201`.

### Design decisions
- **`chat_messages.sender_id` stores `users.id`**, not `patient_profiles.id` / `doctor_profiles.id` — both roles share the same `users` table, so this keeps "whose message is this" a single consistent id shape for the frontend regardless of sender role, matching how `$_SESSION['user_id']` is already the canonical actor id elsewhere in the project.
- **Session must be `status = 'active'` to message** — enforced via the shared helper's default `$requireActive = true`. Confirmed against the actual schema (`chat_sessions.status ENUM('active','ended')` — no `'pending'` state on the session itself, that lives on `chat_requests`).
- **Message length capped at 2000 characters, app-level only.** `chat_messages.message_text` is `TEXT`, there's no schema-derived number to inherit; `2000` is a default pending mentor confirmation, not derived from any existing convention.
- **Poll page size capped at 50** — a performance guard against a client reconnecting with a stale/zero `last_message_id` and pulling an entire multi-hundred-message history in one response; the bookmark mechanism lets the client catch up over several polls instead.

### `is_read` — contracted but unspecified (not implemented this session)
The Database Design sheet lists **"Insert / list / mark read"** as the contracted operations for `chat_messages`, but no BE-XX endpoint or T-08/09/10 test case specifies exactly when `is_read` should flip. Nothing in `messages.php` writes to it — every inserted row stays `is_read = 0` indefinitely. Two designs were considered:
- **Option A (delivery-based):** mark read on every GET poll, regardless of tab visibility. Contract-compliant with BE-15 as written, no frontend changes, but can mark a message "read" while the recipient's tab is backgrounded.
- **Option B (visibility-based, true read receipt):** gate the read-marking `UPDATE` behind a new `active` flag, sent only when the frontend's Page Visibility API confirms the chat is actually on screen — a documented deviation from BE-15's contracted inputs (`session_id`, `message` / `last_message_id` only).

A full design-proposal doc (`Day11_ReadReceipt_Design_Proposal.docx`) was written for Option B, covering the frontend visibility-detection code, the new `active` query param, and the backend `UPDATE` scoped identically to the poll's own `SELECT` (only messages actually being returned in that response get marked, not an unscoped "mark everything unread as read" — the latter would mark messages beyond the 50-message page cap as read before the client ever received them). Not implemented pending mentor input on which option is wanted.

### Testing
- Postman collection (`Day11_Chat_Messages.postman_collection.json`) — `Setup` folder (patient login, doctor accept → captures `session_id`), `Send Message (POST)` folder (7 requests: valid patient send, valid doctor reply, empty message T-10, oversized message T-10, unauthenticated T-08, third-user T-09, non-active-session), `Poll Messages (GET)` folder (5 requests: full history, new-only via `last_message_id`, unauthenticated poll T-08, third-user poll T-09, missing `session_id`).
- Test plan (`Day11_Test_Plan.docx`) — 13 cases (D11-01–D11-13), same landscape six-column format as prior days.
- SQL proof queries (`Day11_SQL_Proof_Queries.sql`) — 6 queries: table structure, full session history, polling-window proof, T-10 no-invalid-message-stored check, sender-identity cross-check against `chat_sessions` participants, and a T-09 structural proof (zero rows where a message's sender maps to neither the session's patient nor doctor).

### Bugs caught during build/review
- **`checkRequiredFields()` return value was silently discarded.** The function returns an array of missing fields rather than exiting internally (unlike `sendError()`/`sendSuccess()`); the first draft called it and ignored the result, meaning a request missing `session_id`/`message` would have skipped validation entirely and failed later with a less meaningful error. Caught by checking the actual function body rather than assuming its behavior from the name; fixed to capture the return value and `sendError(400, ...)` on non-empty.
- **Postman oversized-message test (`#4`) was broken, twice.** First pass used `{{$randomLoremParagraphs}}`, a Postman dynamic variable that generates real newline-separated paragraphs; substituted directly into a quoted JSON string, the raw newlines produced invalid JSON — `json_decode()` failed, `$input` fell back to `[]`, and the response reported `session_id` *and* `message` both "missing" even though `session_id` had a real value. Root cause traced by reasoning through the substitution rather than assuming the validation logic was at fault. Second pass replaced the dynamic variable with an instructional placeholder string (`"...(paste 2000+ chars for manual run)"`) that was then pasted in verbatim instead of being expanded — under 2000 characters, so it passed validation and inserted a real row (`201` instead of the expected `400`). Fixed with a genuine static, single-line, 2000+-character filler string.
- **Test plan listed a non-active-session case (D11-11) that had no matching Postman request.** `[messages.php #12]` and an `ended_session_id` collection variable added to close the gap.

### Open items flagged for mentor
1. **`is_read` behavior** — see design section above. Needs a decision between Option A (delivery-based, contract-compliant) and Option B (visibility-based, requires a documented BE-15 deviation) before either gets built.
2. **D11-12, two-browser send/fetch smoke test — skipped.** Postman's single shared cookie jar can't hold two simultaneous authenticated identities, so a genuine concurrent two-participant test isn't practical through Postman alone. A sequential single-window alternative (switch identity via re-login between each send/poll step) was worked out as a substitute but not run this session; real two-browser testing is blocked on a frontend chat UI that doesn't exist yet.
3. **BE-16 (`session.php`) is confirmed as the correct home for session-ending logic** (manual end w/ T-14 idempotency, auto-end on low balance per T-13, and T-12's disconnect/heartbeat case) but wasn't built this session. T-12 specifically requires "a defined heartbeat/grace or auto-end rule" that isn't specified anywhere in the spec sheets — this is a real open design question for Day 12, not just an implementation detail, and worth raising alongside the other three items above.

### Deferred
- `is_read` / read-receipt implementation — blocked on mentor's Option A vs B decision.
- D11-12 two-browser test — blocked on either a frontend UI or explicit sign-off on the sequential-login Postman substitute.
- `$requireActive = false` path on `getAuthorizedSession()` — added this session but has no caller yet; first real exercise expected once Day 12's `session.php` needs to read an already-ended session.

## Server-Side Billing Engine (Day 12)

### Updates to earlier days
- **`getAuthorizedSession()` (`src/chat/session_helpers.php`) SELECT extended.** Day 11 pulled this helper out with just enough columns for `messages.php` (`id, request_id, patient_id, doctor_id, status`) — confirmed-second-use extraction, per the project's usual rule. Day 12's `session.php` and `billing_service.php` are the second *consumer* of the helper but need a wider row: `started_at`, `last_heartbeat_at`, `ended_at`, `rate_per_minute` weren't selected at all. Surfaced immediately as PHP "Undefined array key" warnings on the very first GET test rather than anything subtle — the SELECT was just missing columns nobody had needed yet. Fixed by widening the query; purely additive, `messages.php`'s existing usage is untouched.
- **`api/chat/respond.php` patched twice** (Day 10, already deployed and tested).
  1. `action=accept` now inserts the `billing_records` row as `pending`, in the same transaction as the `chat_sessions` insert, immediately after `rate_per_minute` is known. Means every session has exactly one billing row for its entire lifetime by construction, rather than relying on `finalizeBilling()`'s defensive fallback insert to paper over sessions accepted before this patch existed.
  2. Follow-up same-day patch: the `admin_settings` lookup at accept time now also captures the row's `id`, and `chat_sessions.admin_settings_id` gets set alongside `rate_per_minute` — see commission-locking design decision below.
- **`chat_sessions` schema extended twice this session**: `last_heartbeat_at` (billing/liveness checkpoint) and, in a same-day follow-up, `admin_settings_id` (FK to whichever `admin_settings` row was active at accept time).

### Endpoints
- `GET / POST /api/chat/session.php` (BE-16) — session-participant-only, same auth shape as Day 11's `messages.php` (`requireAuth()` with no fixed role, participation checked per-session via `getAuthorizedSession()`).
  - **GET (status):** `session_id` only. Calls `getAuthorizedSession(..., $requireActive = false)` — the first real exercise of that flag since Day 11 added it preemptively for exactly this case. Returns session timing, current wallet balance, a `low_balance_warning` flag, and the `billing_records` row — now a genuinely live running total mid-session (see below), not zeros until finalize.
  - **POST, action=heartbeat:** live client keep-alive. Calls `advanceBilling()` to bill elapsed time since the last checkpoint; if the wallet can't afford the full elapsed window, bills the affordable partial seconds and finalizes the session inline (`end_reason = 'low_balance'`, T-13) rather than letting balance go negative.
  - **POST, action=end:** client-triggered finalize (`end_reason = 'manual'`). Idempotent — a second `end` call for an already-finalized session returns the existing `billing_records` row rather than re-billing (T-14).
- `src/billing/close_stale_session.php` — not routed through `public/index.php` in the intended design; a standalone script meant to run via Hostinger hPanel Cron Job. Finds `active` sessions silent past the grace window and finalizes them (`end_reason = 'auto_timeout'`), billing arrears in full up to `checkpoint + grace` rather than writing off the silent period.

### Design decisions
- **`billing_records` row lifecycle changed from insert-at-end to insert-at-accept.** Originally drafted as `finalizeBilling()` inserting a fresh row, switched to the row being created `pending` at accept time and `finalizeBilling()` only ever `UPDATE ... WHERE billing_status = 'pending'`. Cleaner T-14 guard than the original insert-based approach — a `rowCount() === 0` check is atomic under MySQL's row locking, no exception-catching needed.
- **Heartbeat interval (30s), grace window (90s), low-balance warning threshold (60s remaining)** — none of these have a spec value anywhere in the workbook; all three are proposed conventions, flagged inline as `⚠️ MENTOR REVIEW` in `billing_service.php`.
- **Low-balance partial billing:** when a heartbeat's elapsed-time cost exceeds what's affordable, the affordable *partial* seconds are billed (not written off) before ending the session — matches Mandatory Rule #7's per-second billing language and keeps `billing_records`/`wallet_transactions`/doctor earnings reconciling exactly.
- **Hostinger does support real cron** (confirmed via search this session, correcting an earlier wrong assumption that shared hosting had no cron at all) — changed the T-12 design from a "lazy discovery on next request" fallback to an actual scheduled sweep.
- **`commission_percent` locked at accept time (same-day follow-up decision) — but NOT via the same mechanism as `rate_per_minute`.** Rather than adding a dedicated `chat_sessions.commission_percent` column (a new column per setting we'd ever want to lock), `chat_sessions.admin_settings_id` stores a reference to *which* `admin_settings` row was active at accept time — `admin_settings` is already versioned (append-only + `is_active` flip), so the historical row is always recoverable via a join, not just its value at one moment. `finalizeBilling()` looks commission up through that join, once, at finalize time, with a defensive fallback to a live lookup for sessions predating the column (`admin_settings_id IS NULL`).
  - **Deliberately NOT applied to `rate_per_minute`**, despite being the "same kind" of setting: `rate_per_minute` is read on every `advanceBilling()` call — every heartbeat, for the whole life of a session — so a join-based lookup there would mean an extra query per heartbeat tick for something already cheaply available as a denormalized column. `commission_percent` is read exactly once per session, at finalize, so the join cost there is negligible. High-frequency reads stay denormalized; low-frequency reads go through the reference. Both `chat_sessions.rate_per_minute` and the row `admin_settings_id` points to should agree if queried — a useful audit cross-check, not just a design curiosity.
  - **`minimum_balance` deliberately left live**, not locked — treated as a real-time risk/protection policy rather than a per-session price term, so a platform-wide change to it applies immediately even to in-progress sessions, unlike price terms a patient already agreed to.
- **`billing_records` pending row now updates incrementally (same-day follow-up).** `advanceBilling()`, only on a genuine live heartbeat (`$touchHeartbeat = true`), recomputes `duration_seconds`/`gross_amount` as an absolute span from `started_at` (not accumulated tick-by-tick, so no drift possible) and writes it to the `pending` row. `commission_amount`/`doctor_amount` are deliberately left at `0` until real finalize — they depend on the locked `commission_percent` lookup, which only `finalizeBilling()` performs, keeping `advanceBilling()` itself unaware of commission entirely.

### Testing
- Postman collection (`day12_session_billing.postman_collection.json`) — `Setup` folder (patient/doctor/third-party logins, wallet recharge, request→accept), four method folders `(A)` GET status through `(D)` cron sweep, 20 requests tagged `[session.php #N]` / `[cron #N]` matching the test plan.
- Test plan (`Day12_Test_Plan.docx`) — 20 cases across GET status, heartbeat, end, and cron sweep, same landscape six-column format as prior days.
- SQL verification queries (`day12_verification_queries.sql`) — one block per test number, including reconciliation checks (`gross_amount = commission_amount + doctor_amount`) and idempotency checks.
- All 20 originally-planned test cases passing as of final pass, run locally against XAMPP + phpMyAdmin.
- **Commission-locking verification (follow-up, not yet run):** create a session, mid-session flip `admin_settings.is_active` to a different row with a different `commission_percent`, finalize, and confirm `billing_records.commission_amount` reflects the *original* locked value, not the newly-active one. Not yet executed — see Deferred.

### Bugs caught during build/review
- **`getAuthorizedSession()` missing columns** — see Updates to earlier days above. Caught immediately via PHP warnings on the first live GET test.
- **`finalizeBilling()` double-computation on the low-balance heartbeat path.** `session.php`'s heartbeat handler calls `advanceBilling()` once itself to check `ended_early`, then calls `finalizeBilling()` on that branch — which internally called `advanceBilling()` a second time, using the same in-memory `$session` array. That array's `last_heartbeat_at` was stale relative to what the first call had already persisted to the DB, so the second call recomputed the identical elapsed window; the wallet debit was correctly blocked as a duplicate, but the affordability check re-ran against the now-already-debited balance and collapsed the checkpoint back to the start of the window — silently zeroing `duration_seconds`/`gross_amount` on the persisted `billing_records` row while the underlying wallet debit was actually correct throughout. Caught by a real T-13 test run producing an internally-inconsistent response. Fixed by re-fetching `last_heartbeat_at` fresh from the DB immediately before `finalizeBilling()`'s internal `advanceBilling()` call.
- **`last_heartbeat_at` / `ended_at` conflation — found one layer deeper, after the fix above.** `advanceBilling()` unconditionally persisted its computed checkpoint to `last_heartbeat_at` on every call, including the internal one `finalizeBilling()` makes on non-heartbeat triggers. This overwrote the true last-ping timestamp with whatever finalize-time checkpoint was computed — invisible in the heartbeat/manual-end paths, but directly corrupting the cron sweep's own grace-window math. Caught by a direct question about why a completed `auto_timeout` row showed `last_heartbeat_at` and `ended_at` as identical timestamps when they shouldn't necessarily be. Fixed by adding a `$touchHeartbeat` bool param to `advanceBilling()` (default `true`): only genuine live heartbeat calls persist to `last_heartbeat_at`; `finalizeBilling()` passes `false` and writes its own computed checkpoint to `ended_at` instead. Re-verified post-fix on a fresh `auto_timeout` closure: `last_heartbeat_at` and `ended_at` now correctly show two distinct values, 90 seconds apart.

### Open items flagged for mentor
1. **Hostinger hPanel cron path unconfirmed.** Sweep verified locally via direct PHP CLI invocation and a temporary `index.php` route only; the actual deployed absolute path for the hPanel Cron Job setup still needs confirming via File Manager once this ships.
2. **Same residual T-14 race as flagged in the original design** — true-concurrent (not sequential) double `end` requests could in a narrow window both pass the pre-finalization check before either updates. No distributed lock available on shared hosting to fully close this; T-14's actual (sequential) scenario is fully covered by the `pending`→`finalized` row-count guard.
3. **`minimum_balance` intentionally left live, not locked** — see Design decisions above. Confirm this "risk policy vs. price term" framing is the intended reasoning, not just an assumption.

### Deferred
- **Commission-locking verification test** (mid-session settings flip, confirm old commission_percent still applies) — designed but not yet run.
- **Temporary `index.php` test route must be removed before any production-adjacent deploy.** `GET /src/billing/close_stale_session` was added solely to make the sweep reachable from Postman on XAMPP. Has no auth and can finalize real billing / close real sessions — fine for local testing, not something that should ship.
- **Real Hostinger cron job setup** — blocked on confirming the deployed path via hPanel File Manager.

## Doctor Earnings & Reports (Day 13)

### Updates to earlier days
- **`finalizeBilling()` (`src/billing/billing_service.php`, Day 12) patched, not rewritten.** The `doctor_earnings` insert is appended to the same branch that flips `billing_records` to `finalized` — same function call, same request, so the two writes can never drift apart regardless of which of the three callers triggered finalization (manual end, low-balance auto-end, cron sweep). Reuses Day 12's existing T-14 idempotency guard: the insert only runs on the branch that actually just finalized, never on either early-return `already_finalized` path, so a session can never accumulate two `doctor_earnings` rows. Delivered as a marked patch (find/replace block), not a silent full-file rewrite.
- **`doctor_earnings.status` column added via migration** (`ENUM('unpaid','paid')`, default `'unpaid'`) — the table existed from the original schema but had no settlement-state column. No payout/settlement workflow exists yet in Days 13–15; added now anyway since it's cheap alongside an already-planned migration and avoids a second one later if a payout feature lands.

### Endpoints
- `GET /api/doctors/earnings.php` (BE-18) — doctor-only, own earnings only (`doctor_id` derived server-side from `$_SESSION['user_id']`, never accepted as a param — same ownership-authorization pattern as the rest of the project). Returns `today_earning`/`total_earning` (always computed independent of any filter) plus a paginated `ledger` joined back to `billing_records` for the session-level breakdown behind each row. Optional `from`/`to` date params narrow the ledger only, never the two summary totals — deliberate, so a doctor browsing a specific date range still sees stable lifetime figures.
- `GET /api/admin/earnings-summary.php` — admin-only, unnumbered (see Design decisions). Returns `platform_totals` (gross/commission/doctor split across all finalized sessions) and a `doctor_breakdown` array grouped per doctor. Sourced from `billing_records`, not `doctor_earnings` — see below.

### Design decisions
- **`doctor_earnings`** Keeps a doctor-facing ledger separate from the session-level billing truth, following the same append-only pattern already used for `wallet_transactions`/`admin_settings`. 
- **Admin summary sourced from `billing_records`, not `doctor_earnings`.** `billing_records` is the platform-wide source of truth including the commission leg, which `doctor_earnings` deliberately doesn't carry (it's doctor-facing only — `doctor_id`, `amount`, `status`). Only `billing_status='finalized'` rows are counted; a `pending` mid-session row hasn't actually been collected yet.
- **Admin summary endpoint is unnumbered** — not in the PHP API Contract sheet, added on the same precedent as the `pending.php ?status=` addition, to satisfy the 15-Day Plan's "admin summary endpoints" line.
- **"Patient transactions" required no new endpoint.** Confirmed `wallet/details.php` (BE-08) already returns the full `wallet_transactions` list including `chat_billing_increment` rows from the billing engine — satisfies the Day 13 plan line as-is.

### Testing
- Postman collection (`day13_postman_collection.json`) — four folders: Doctor Earnings (BE-18), Session Finalize Triggers, Admin Earnings Summary, Wallet Details regression. JSON-typed request bodies, per-request docs on every request, tagged `[file.php #T13-xx]` matching the test plan.
- Test plan (`Day13_Test_Plan.docx`) — 18 cases across doctor earnings, finalize triggers, admin summary, migration verification, and the BE-08 regression check, same landscape six-column format as prior days.
- SQL verification queries (`day13_verification_queries.sql`) — reconciliation check (`gross_amount = commission_amount + doctor_amount`), duplicate-insert check on `doctor_earnings.billing_id`, and manual cross-check queries for both endpoints' totals.
- **Ran live against local XAMPP/MySQL (`vet_consult_db`), doctor_id 6 (doctorA), patient_id 5 (profiletest).** All figures cross-checked against matching SQL, not just eyeballed from the API response:
  - **T13-01** (happy path totals) — confirmed, matched `SUM(amount)` exactly, including after new rows were added mid-testing.
  - **T13-03** (pagination) — confirmed on 25 seeded rows (real `chat_requests`→`chat_sessions`→`billing_records`→`doctor_earnings` chains, not fabricated JSON). `?page=2&per_page=10` returned the correct 10-row slice in the correct order; `pagination.total` matched `COUNT(*)`.
  - **T13-04** (date filter) — confirmed with 2 rows explicitly backdated to the prior day; filtered ledger returned exactly those 2, totals stayed correct and unaffected by the filter throughout.
  - **T13-08/T13-09** (reconciliation + row creation on manual end) — confirmed on real sessions; `commission_amount + doctor_amount == gross_amount` held at 25% commission / ₹20/min rate across multiple runs; each `end` call produced exactly one `doctor_earnings` row, `status='unpaid'`.
  - **T13-12** (duplicate-finalize guard) — confirmed, first observed *unintentionally* (a session got double-ended before real elapsed time was tracked, surfacing `already_finalized: true` with frozen zero values from the premature first call), then reproduced properly on a session with a genuine non-zero billed amount.
  - **T13-13/T13-15/T13-16** (admin summary — totals, date range, name resolution) — confirmed after the `doctor_profiles` table-name fix (see Bugs below). Platform totals matched the manual `SUM()` across `billing_records`; date-range filter correctly narrowed both `platform_totals` and `doctor_breakdown`; `doctor_name` matched `doctor_profiles.full_name`.
- **Process note:** several apparent "mismatches" mid-testing (e.g. `today_earning` differing by exactly ₹3.00 between two captures) were not bugs — new rows landed between screenshotting the API response and running the SQL cross-check, from other tests running in the same session. For future days: capture the API response and run its SQL verification back-to-back, without other requests firing in between, or the numbers will legitimately drift and look like false failures.

### Bugs caught during build/review
- **Unnecessary `users` join in `earnings-summary.php`'s first draft.** Joined `doctors` to a `users.name` column that doesn't exist — `users` only carries auth fields (`email`, `pin_hash`, `role`). Removed the join entirely once the schema was confirmed; `doctor_profiles.full_name` (see next bug) is the real display name field.
- **Real runtime bug, caught via live testing, not review: wrong table name.** `earnings-summary.php`'s per-doctor breakdown query joined against a table named `doctors`, based on the shorthand used in project notes. Actual table is `doctor_profiles` — confirmed via `SHOW TABLES LIKE '%doctor%'` after a `PDOException: Base table or view not found` fatal error on first live test. Fixed the join target; `earnings.php` itself never joins to a doctor-profile table at all, so this was isolated to the one file.

### Open items flagged for mentor
1. **No try/catch around the `doctor_earnings` insert in `finalizeBilling()`.** A failure there surfaces loudly (uncaught exception) rather than being swallowed, on the reasoning that a `billing_records` row finalized with no matching `doctor_earnings` row is a real bug worth seeing immediately. No reconciliation job exists yet to catch this after the fact if silent-fail-and-reconcile-later is preferred instead.
2. **T13-08/T13-09 consolidation, not yet applied.** Both check the same `end` call's response — reconciliation math and row-creation are two assertions on one result, not two separate tests. Proposal: keep the ID as T13-08, retire T13-09 as standalone. Needs updating in the test plan docx, screenshot template docx, and Postman collection request tags.
3. **T13-13/T13-16 consolidation, same reasoning, not yet applied.** Both check the same default `GET /api/admin/earnings-summary` response — platform-totals-vs-breakdown math and doctor-name lookup correctness are two assertions on one result. Proposal: keep the ID as T13-13, retire T13-16 as standalone. Same file list as above.

### Deferred
- **T13-11 (cron auto-timeout) not run via Postman** — genuinely can't be, same as Day 12's cron sweep. Requires backdating a session's `last_heartbeat_at` in phpMyAdmin, then running `close_stale_sessions.php` via CLI, and checking `billing_records`/`doctor_earnings` directly afterward.
- **Two-browser smoke test still deferred from Day 11–12** (Postman single-cookie-jar limitation). Doesn't block `earnings.php`/`earnings-summary.php` themselves (single-actor reads), but the finalize-trigger tests (T13-08 through T13-12) do involve a live patient+doctor session pair and inherit the same limitation Day 12 already flagged.