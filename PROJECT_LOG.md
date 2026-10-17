# Anibros CRM — multi-client edition (PHP · MySQL · HTML/CSS/JS) — PROJECT LOG

Port of the Node.js / PostgreSQL "Anibros Design" multi-client CRM (reference copy, never modified:
`D:\work\Xampp\htdocs\Anibrosdesign-CRM`) to a stack that runs on ordinary cPanel hosting (PHP + MySQL/MariaDB + cron + SMTP).
Target: `crm.anibrosdesign.com`. Local test bed: XAMPP (PHP 8.2, MariaDB 10.4 on port **3307**, phpMyAdmin).

Rule set from the owner: same UI, same core behaviour, same data isolation, every feature of the current project, build everything
first and test at the END (not after every module), log everything here.

---------------------------------------------------------------------------------------------------------------------

## 0. How to resume (read this first)

* Phase checklist is in section 2. The newest log entries are at the bottom (section 6).
* Never print or log secrets. Never touch the Node project, its DB, or ports 4000/5174/5442/4200/5274.
* Never write PHP / SQL containing backticks or backslashes through bash heredocs: use the Write / Edit tools.
* XAMPP DB: host 127.0.0.1 port 3307 user root (no password). Database for this project: `anibros_crm_multiclient`.
  Other databases in that server (anibros_crm, anibros_crm_test, anibros_os, ...) belong to the owner: do not touch.
* Open the app locally: http://localhost/Anibros-CRM-multiclient/ (root .htaccess forwards to `public/`).

---------------------------------------------------------------------------------------------------------------------

## 1. Architecture decisions (and why)

| Topic | Decision | Why |
|---|---|---|
| Server language | Pure PHP 8.1+ (no Composer, no extensions beyond pdo_mysql, mbstring, openssl, curl, json) | cPanel has no Node; zero-dependency PHP uploads with FTP/File Manager and runs everywhere |
| UI | The existing React UI is compiled once to static HTML/CSS/JS (`frontend/` holds the source, `public/assets` the build). The browser never needs Node. The PHP API implements the **same `/api/v1/*` contract (221 endpoints)** | Pixel-identical UI, no re-design risk. Rebuilding needs Node only on a developer machine, never on the host |
| Database | MySQL / MariaDB (utf8mb4, InnoDB, READ COMMITTED), UUID keys (CHAR(36)), UTC DATETIME(3) | Available on cPanel. Same IDs as before, so export/import of data is possible |
| Data isolation (replaces PostgreSQL row-level security) | Layered, see 1.1 | MySQL has no RLS. This reproduces the same guarantees as closely as MySQL allows |
| Background work | `bin/worker.php` run by a **cPanel cron job every minute** (loops ~55 s) + opportunistic pump after web responses | cPanel cannot keep a daemon alive |
| Realtime | Polling endpoint (`GET /api/v1/realtime/events`) replaces the WebSocket (UI patched); same invalidation logic | shared hosting cannot hold WebSockets |
| Mail | Built-in SMTP client (SSL/STARTTLS, AUTH LOGIN/PLAIN) with `mail()` fallback; jobs queue with retries | cPanel mailbox works; no PHPMailer dependency |
| Passwords | `password_hash(PASSWORD_ARGON2ID)` if available else bcrypt; same policy, same timing equalisation | same security properties |
| Secrets at rest | AES-256-GCM via OpenSSL with AAD binding, same token format `v1.<kid>.<iv>.<tag>.<ct>` | identical scheme to the Node app |
| Routing/base path | App works at a domain root (cPanel sub-domain) **and** inside a sub-folder (XAMPP): base path detected at runtime and injected into the SPA | local testing without virtual hosts |

### 1.1 Data isolation design (the part that must not be weakened)

PostgreSQL enforced tenant isolation inside the database with RLS policies. MySQL cannot, so isolation is rebuilt in layers; each layer
holds on its own:

1. **Schema:** every business row has `tenant_id NOT NULL`; every cross-table reference is a *composite* foreign key
   `(child_id, tenant_id) -> parent(id, tenant_id)`, so a row can never point at another tenant's row (same as the Node schema).
2. **Database views = row-level security.** Application code never reads or writes the base tables of a tenant. It uses views
   `v_<table>` that contain `WHERE tenant_id = app_tenant()` and `WITH CHECK OPTION`. `app_tenant()` returns a session variable that only
   the data layer sets, per transaction. Proven on MariaDB: index use unchanged, cross-tenant INSERT refused (SQLSTATE 44000), cross-tenant
   UPDATE/DELETE touch 0 rows, no tenant set = 0 rows visible. Identity tables (`users`, `tenants`, `tenant_memberships`,
   `organisations`) get the same visibility rules as migration 034 of the Node project (workspace / user / identity-flow contexts) with
   separate write views where write rules differ from read rules.
3. **Data layer contexts** (`App\Core\Db`): `Db::tenant($actor)` (workspace), `Db::asUser($id)` (agency layer), `Db::identity()`
   (sign-in, sign-up, sessions, tokens, invitations: base tables), `Db::system()` (worker/installer: base tables). The SQL rewriter maps
   table names to views automatically for the first two; a table not available in that context raises an error instead of leaking.
4. **Code rule + test:** only an allow-list of files may call `Db::identity()` / `Db::system()`; an automated test greps the code base.
5. **No triggers, no stored procedures that need SUPER** (cPanel binary-logging restriction): the Node project's trigger logic
   (version bump, stage sync, status guard, stage-history snapshot, property history, first-touch guard) lives in PHP services inside the
   same transaction. Only two tiny `NO SQL` / `READS SQL DATA` functions exist (`app_tenant()`, `app_user()` and four identity helpers).
6. **Grants hardening (optional, VPS / hosts that allow GRANT):** `database/hardening.sql` revokes base-table access from the application
   account so the views are the only door. On cPanel shared hosting the account has database-wide rights, so layers 1-5 apply.

Honest limit: on shared hosting the application's MySQL account can technically read base tables (cPanel cannot grant per-table rights).
The Node/Postgres version enforced it for the runtime role. All code paths still go through the views, and a test proves it.

---------------------------------------------------------------------------------------------------------------------

## 2. Phase checklist (tick as built; tests only at the end)

- [x] P0  Folder skeleton, log, memory note
- [x] P1  Schema (`database/schema.sql`, 63 tables), views generator (`app/Core/Views.php`), migration runner, CLI installer (`bin/install.php`, `bin/migrate.php`) — applied to XAMPP DB `anibros_crm_multiclient` (63 tables, 75 function/view statements OK)
- [x] P2  Core library written (`app/Core/*`: Config, Db/Tx/Sql rewriter, V validator, Router, Http, Csrf, Crypto, Totp, Password, Mailer+SMTP, Jobs, Events, Audit, Notify, RateLimiter, Idempotency, Storage, Pagination, EventLog, App front controller). Still to write: `Worker` (cron + pump), `public/index.php`, `.htaccess`
- [x] P3  Identity: sessions, authenticator, signup/login/MFA, members + invitations, API keys, workspace settings (+ `Leads\Seeds`, `Leads\Routing`)
- [ ] P4  Agency layer (clients, team, staff, agency leads, report, xlsx)
- [x] P5  Contacts, consents, merge/undo (written, untested)
- [x] P6  Leads, pipelines, stages, transitions, saved views, CSV import/export, scoring, custom fields (written, untested)
- [x] P7  Tasks, notifications, recurrence (written, untested; the reminder/overdue dispatch job is still to write in the Worker)
- [x] P8  Inventory (properties, projects, files), matching, viewings (+ics), deals (reservations, shortlist) (written, untested)
- [x] P9  Analytics: attribution, spend, reports, dashboard, home, search (written, untested)
- [ ] P10 Automation engine, outbound webhooks, inbound lead sources (+ website capture script), messaging (WhatsApp/email), monitor
- [ ] P11 Worker / cron / maintenance, realtime polling, health endpoints
- [ ] P12 Frontend adaptation (base path, realtime polling) + production build into `public/`
- [ ] P13 Demo data seeder
- [ ] P14 Deployment guide (cPanel), .htaccess hardening
- [ ] P15 TEST PHASE (PHP test runner, isolation proofs, API contract check vs OpenAPI, browser smoke)
- [ ] P16 Final report

---------------------------------------------------------------------------------------------------------------------

## 3. cPanel facts (from the owner's screenshot `aaaa.pdf`, cPanel 110)

Available: File Manager, FTP, Backup, Git Version Control, MySQL Databases + Wizard + phpMyAdmin, Remote MySQL, MultiPHP Manager + INI
editor, Domains / Subdomains (document root selectable), Redirects, Zone Editor, SSL/TLS (AutoSSL), Cron Jobs, Terminal, SSH Access,
Email Accounts (65 existing, 104 forwarders), Application Manager (Passenger), Error Pages, Indexes.
=> Hosting plan: create sub-domain `crm.anibrosdesign.com` with document root = `<project>/public`; MySQL database + user in cPanel;
cron job `* * * * * /usr/local/bin/php /home/<user>/<project>/bin/worker.php`; mailbox `no-reply@anibrosdesign.com` for SMTP.

---------------------------------------------------------------------------------------------------------------------

## 6. Log (newest last)

### 2026-10-16 — Session start
* Request received: convert the project to PHP/HTML/CSS/JS for cPanel, new folder `Anibros-CRM-multiclient`, same UI/core/isolation.
* Surveyed source: 63 tables, 34 migrations, 221 API endpoints, ~21k lines of server code, React UI ~18k lines (+ generated types).
* Surveyed environment: XAMPP PHP 8.2.12 (pdo_mysql, mbstring, openssl, curl, intl, gd), MariaDB 10.4.32 on 3307 (phpMyAdmin bound to 3307),
  Apache with mod_rewrite + AllowOverride All. Port 3306 is a different MySQL, left alone.
* Experiment (scratch DB, deleted afterwards): view-based tenant isolation on MariaDB — indexes still used (ref ix), 0.8 ms vs 0.7 ms,
  cross-tenant INSERT refused, cross-tenant UPDATE affects 0 rows, no tenant => 0 rows. Design 1.1 confirmed.
* Created folder skeleton and this log.

### 2026-10-16 — Foundation built (no tests yet, by design)
* `database/schema.sql`: 63 tables ported from the Postgres schema (ENUMs, composite tenant FKs, generated helper columns instead of partial
  unique indexes, no triggers). Applied once to a scratch DB to catch DDL errors, then installed into `anibros_crm_multiclient`.
* Isolation views: `v_<table>` for 49 workspace tables + `v_/w_` views for users, organisations, tenants, tenant_memberships, `v_notification_reads`,
  `w_jobs`. Found and fixed: MariaDB refuses WITH CHECK OPTION on a view whose condition calls a function that reads the view's own table, so the
  WRITE views use `FIND_IN_SET(org, app_admin_orgs())` where `@app_admin_orgs` is computed by the data layer (cached per request, refreshed after any
  tenant_memberships write). Read views keep the lookup functions.
* Data layer (`App\Core\Db`): contexts `tenant / asUser / identity / system`; `Sql::prepare` rewrites every FROM/JOIN/UPDATE/INSERT INTO/DELETE FROM table
  to its view, refuses unknown/identity/system tables in scoped contexts and refuses comma joins; arrays expand for `IN (?)`; DATETIME columns come back
  as ISO-8601 `...Z`; `Tx::insert/update/delete/insertIgnore` helpers (insertIgnore = ON DUPLICATE KEY no-op, never INSERT IGNORE); transactions join
  when nested; deadlock retry; READ COMMITTED.
* Conventions for the port: write `version = version + 1` explicitly in every UPDATE that the Node trigger bumped (contacts, tasks, projects, properties,
  reservations, viewings, leads (only for significant columns)); no `RETURNING` (re-select by id); no `ILIKE` (ci collation); arrays/JSON as text with
  `Json::enc/dec`; use `Map::row` for casts; `SKIP LOCKED` not available (MariaDB 10.4): jobs are claimed with `UPDATE ... ORDER BY ... LIMIT` stamped with a
  worker token.
* Modules written so far: Identity (Sessions, Authenticator, AuthService, IdentityRoutes), Members, ApiKeys, Workspace, Contacts (Normalize with a built-in
  E.164 phone normaliser instead of libphonenumber), CustomFields, Leads\Seeds/Routing.
* NEXT: Tasks (+Recurrence, Notifications), Leads (service, pipelines, saved views, import, scoring, csv, reason codes, channels, touches) + Deals ledger,
  Inventory, Viewings, Deals, Analytics, Automation, Outbound, Inbound, Messaging, Monitor, Home, Search, Dashboard, Agency, Realtime, Worker.

### 2026-10-16 — Wave 2 written: Contacts, Leads, Tasks, Inventory, Viewings, Deals, Analytics, Dashboard / Home / Search (no tests yet, by design)
* Leads: service (list/get/create/update/move/assign/claim/handlers/bulk/board/forecast/export/rescore), pipelines, saved views, CSV import (job `leads.import`,
  continues itself after 25 s), scoring, routing; Tasks (+ Recurrence, notifications); Deals ledger (complete / reverse a sale).
* Inventory: properties (availability state machine, price + status history through `PropertyWriter`, unit-number uniqueness through a generated-column unique
  key), projects (unit counts from a grouped derived table instead of LATERAL/FILTER), files (bytes sniffed, content de-duplicated per entity, blobs in
  `storage/uploads`, download with ETag + sandbox CSP), rule-based matching (weights 40/25/20/15).
* Viewings: the Postgres function `schedule_viewing` is now PHP (property row lock, double-booking checks for the property and for the agent, activity, audit,
  event), reminder task per visit, complete / no-show / cancel, `.ics` export.
* Deals: offers -> reservation -> completed sale / closed. One holder per unit is a unique key on a generated column (the PG partial unique index). The lead
  advances through `LeadsService::moveInTx` inside a savepoint. Shortlist.
* Analytics / Dashboard: the PG reports used CTEs, LATERAL, FILTER, window functions and `percentile_cont`. They are rewritten as nested derived tables
  (`Analytics\Cohort`) and plain aggregates so they run on MySQL 5.7 / MariaDB shared hosting. Medians and time-zone buckets (hour-of-week grid, daily series,
  trend buckets) are computed in PHP because time-zone tables (`CONVERT_TZ`) may be missing on a shared host.
  Deviation: the Node flow report took "the first non-null bucket median" by accident; the port reports the true overall median of first-reply times.
* Routes registered: identity, members, api-keys, workspace, contacts, custom-fields, leads (+pipelines, saved-views), tasks (+notifications), properties,
  projects, files, matching, viewings, reservations, shortlist, attribution, spend, reports, dashboard, home, search.
* NEXT: Automation, Outbound webhooks, Inbound lead sources (+ capture script), Messaging, Monitor, Agency, Realtime polling, Worker, front controller +
  .htaccess, frontend adaptation + build, seeder, deployment guide, then the test phase.
# 2026-10-16 — Migration reconciliation / Automation worker completion (in progress)

- Inspected both repositories and preserved the PHP target's existing uncommitted migration work.
- Confirmed the Automation API existed, but the worker registered only CSV import handlers; enabled rules could never run.
- Added the cursor-backed automation event bridge and durable dispatch/retry handlers. Runs claim `(rule_id, trigger_event_id)` before applying assignment, task, and notification effects, with execution history/audit/domain-event updates in the same tenant transaction.
- Added `docs/MIGRATION_PARITY.md` as the persistent source-to-target feature register. Missing modules remain Agency, Inbound, Outbound, Messaging, and Monitor. Realtime now has a cPanel-compatible poll endpoint and source adaptation (pending production build).
- No tests or build commands were run, per the mandated implementation-before-testing ordering.


### 2026-10-16 — Phase B: Boot Real React UI in PHP Port
- Inspected startup defects in `Anibros-CRM-multiclient`:
  1. Identified MariaDB connection initialization error in `app/Core/Db.php` (comma-separated `SESSION TRANSACTION ISOLATION LEVEL` inside `SET NAMES` throwing SQLSTATE[42000] 1064). Replaced with explicit `$pdo->exec()` statements for charset, timezone, isolation, and sql_mode. Verified `readyz` returns HTTP 200 with `database: connected`, schemaVersion `000_schema`.
  2. Identified that previous built assets referenced root-absolute `/assets/` paths failing on subdirectory deployments. Populated `frontend/node_modules` from local reference, configured Vite build to generate relative `./assets/` chunks, and updated `package.json` to automatically promote `index.html` to `app.html` for single-entrypoint PHP SPA injection.
- Re-built frontend with `npx vite build` (20.13s, 0 errors).
- Validated all asset HTTP responses: `.js` and `.css` return HTTP 200 across both `/Anibros-CRM-multiclient/public/` and `/Anibros-CRM-multiclient/` routes.
- Executed real browser journeys via headless Microsoft Edge CDP (v155.0.4283.45):
  - Sign-In route (`/sign-in`): Renders full React Material-UI layout ("Anibros Design", "Every client's leads, one calm place", "Welcome back", email/password inputs). 0 telemetry errors.
  - Sign-Up route (`/sign-up`): Renders agency account creation form. 0 telemetry errors.
  - Forgot Password route (`/forgot-password`): Renders recovery form. 0 telemetry errors.
  - Verify Email route (`/verify-email`): Renders verification feedback state. 0 telemetry errors.
  - API & Error state: Verified live `POST /api/v1/auth/login` interaction. Server returned RFC 9457 401 Problem Document, and React UI displayed "Invalid email or password" error banner honestly.
  - Captured desktop (1440x900) and mobile (390x844) screenshots for all routes.
- Database `anibros_crm_multiclient` remains fully intact with 0 rows in users. Complete login journey requires verified user provisioning or SMTP credentials.

### 2026-10-16 — Phase C: Authenticated Shell & Workspace Parity
- **Controlled Local Identity Provisioning:**
  - Provisioned authentic synthetic user `test_owner_alpha@anibros.local` (Name: `Alex Mercer`, Organization: `Mercer Capital Group`, Kind: `agency_hq`, Role: `tenant_owner`) strictly through the application's native `AuthService::signup` service.
  - Enabled worker mail processing with registered `mail.send` handler. Delivered verification email token to `storage/mail/*.txt` via `FileTransport`.
  - Configured `PDO::ATTR_EMULATE_PREPARES => false` in `Db.php` to support native integer bindings for MariaDB `LIMIT ?` queries.
  - Verified identity via `/verify-email?token=...` link; user record marked `email_verified = 1`.
- **Agency Routes & REST Endpoints:**
  - Registered `\App\Modules\Agency\AgencyRoutes::register()` in `app/Routes.php` providing all 22 REST endpoints mapped to `AgencyService.php`.
  - Fixed syntax in `AgencyService.php` (lines 238 & 259 array closure in validation errors) and `AgencyRoutes.php` (`V::enum` validation).
- **End-to-End Authentication Journey (Automated via Microsoft Edge CDP):**
  - Invalid credentials test: `POST /api/v1/auth/login` returned 401 Problem Document; React UI displayed "Invalid email or password" error banner honestly without throwing uncaught exceptions.
  - Valid credentials test: `POST /api/v1/auth/login` succeeded with HTTP 200, setting HttpOnly session cookie and `anibrosdesign_csrf` cookie.
  - Authenticated session preserved across full page reloads.
  - User avatar menu and logout (`POST /api/v1/auth/logout`) returns HTTP 204, invalidates session cookie, and redirects user to `/sign-in`.
- **Authenticated Shell & Workspace Parity:**
  - Rendered complete topbar and sidebar navigation for Agency HQ (`Mercer Capital Group`).
  - Created second synthetic client workspace `Client Beta Realty` (Kind: `client`, Industry: `real_estate`) via `POST /api/v1/agency/clients` (HTTP 201 Created).
  - Multi-tenant Tab Switching: Topbar renders dynamic browser-style tabs (`All clients` & `Client Beta Realty`).
  - Scope and Tenant Isolation: Switching to `Client Beta Realty` (`?c=<clientId>`) sets client scope, passes `X-Tenant-Id` header, and adapts navigation to full client CRM suite.
  - Unauthorized workspace switching attempt (`00000000-0000-0000-0000-000000000000`) rejected with HTTP 404/403.
- **Core CRM Module Entry Points Verified in Client Workspace:**
  - Overview Dashboard (`/overview`): Renders greeting ("Good afternoon, Alex"), Getting Started tasks, and performance tiles.
  - Leads (`/leads`): Renders search, pipeline view, filter pills (Open, Mine, Needs reply, Follow-up today, Not assigned, Closed), Import, and Add Lead.
  - Contacts (`/contacts`): Renders people and companies directory, export, and search.
  - Deals & Pipelines (`/deals`): Renders sales pipeline stages, deal values, and Kanban cards.
  - Tasks & Activities (`/tasks`): Renders task checklist, due dates, and completion status.
  - Inventory & Properties (`/properties`): Renders units, developments, matching, and offers.
  - Reports (`/reports`): Renders funnel metrics, attribution, lead sources, and spend analytics.
  - Settings (`/settings/client`): Renders details, team assignments, custom fields, pipeline stages, and automation rules.
- **Evidence & Artifacts:**
  - Captured 14 high-resolution desktop (1440x900) and mobile (390x844) PNG screenshots documenting each step, state, and module.
  - 0 unhandled runtime errors in browser telemetry (`window.__telemetryErrors = []`).

### 2026-10-17 — Monitor API implementation
- Confirmed the authoritative React source at `D:\work\Xampp\htdocs\Anibrosdesign-CRM` and the existing PHP target at `D:\work\Xampp\htdocs\Anibros-CRM-multiclient`. The source worktree is clean; pre-existing PHP-target changes were preserved.
- Implemented the PHP Monitor API routes consumed by the existing React monitor client: overview, operational events, audit list, facets, CSV export, and audit detail.
- Added tenant-scoped queries, role/permission checks, cursor pagination, sensitive-field redaction, and append-only audit export recording.
- Added a read-only `v_system_health` view exposing only the global worker heartbeat to tenant reads; tenant writes use `w_system_health` with a check option. The SQL rewriter maps reads and writes separately.
- Added `tests/monitor_contract.php` for API-route, permission, SQL rewrite, and health-view isolation contract checks.
- Verification: PHP syntax checks passed for all changed PHP files; application route registration succeeds (221 routes total), the six Monitor routes register, and health read/write SQL rewrites were inspected successfully.
- Blocked verification: target MariaDB at `127.0.0.1:3307` is not listening. No database mutation, database-backed workflow test, frontend rebuild, or claim of overall migration completion was made.

### 2026-10-17 — Inbound lead-source receiver and worker implementation
- Confirmed the read-only React source at `D:\work\Xampp\htdocs\Anibrosdesign-CRM` and PHP target at `D:\work\Xampp\htdocs\Anibros-CRM-multiclient`; no React files were changed.
- Added the PHP lead-source API consumed by the existing frontend: list/create/update/soft-delete, generated-secret rotation, test delivery, paginated delivery history, retry, and ignore.
- Added Meta, Google, website, and generic provider parsing/normalization; Meta raw-body HMAC and verification challenge; generic timestamped HMAC with replay limit and key alternatives; Google-key removal; website origin policy, honeypot, capture script, and stricter IP rate limit.
- Added a narrow endpoint-ID lookup for public hooks. The endpoint row selects the tenant; authenticated event rows and `inbound.process` jobs are persisted in the same tenant transaction before acknowledging, with the provider event id unique per endpoint.
- Registered the idempotent worker processor with retry/final-failure states, Meta Graph retrieval using the bearer header and `appsecret_proof`, contact/lead creation, duplicate-lead later-touch and timeline behavior, owner notifications, and audit/domain events.
- Fixed a tenant-isolation defect exposed by the inbound contract: SQL inserts with a column list were not being rewritten to tenant views. Insert rewriting now routes scoped inserts through the tenant view while preserving table-valued function handling.
- Added `tests/inbound_contract.php`. Verification passed: PHP lint for 119 application/test files; inbound provider/signature/route/tenant-view/capture/Meta-token-transport contracts; existing Monitor contract; full route registration (221 routes).
- Blocker: MariaDB at `127.0.0.1:3307` is not listening. Therefore the inbound delivery/API/worker workflows, tenant isolation under live database views, and frontend-to-PHP integration have not yet received database-backed end-to-end verification. Inbound is recorded as in progress, not complete.

### 2026-10-17 — Outbound webhooks and messaging implementation
- Added tenant-scoped PHP webhook event/list/create/get/update/delete/secret-rotation/test/delivery-history/retry routes matching `src/api/outbound.ts`. Webhook secrets are AES-GCM encrypted and endpoint-bound; outgoing JSON uses the source HMAC signature and stable delivery id.
- Added public-address validation on create and send, including private/reserved IPv4 and IPv6 ranges, IPv4-mapped IPv6, DNS re-resolution with address pinning, HTTPS-only production behavior, no redirects, bounded response reads, and short timeouts.
- Added an independent cursor consumer over `domain_events`, atomic delivery/job creation, retries up to the source limit, endpoint disable-after-failure behavior, event payload mapping and delivery outcome persistence. Registered the delivery job with the shared-host cron worker.
- Added WhatsApp account read/connect/disconnect and lead messaging routes. Credentials are encrypted with tenant AAD; message jobs use the lead's own contact data, enforce opt-out and rate limits, log the activity transactionally, send through configured SMTP or the official Meta Graph endpoint, retry transient errors and notify the sender of terminal failures.
- Added `tests/outbound_messaging_contract.php`. Its route schema check exposed and fixed a shared validator defect: `V::parseArr` passed an uninitialized by-reference omit flag, causing valid array inputs to throw `TypeError`.
- Verification passed after implementation: PHP lint for 119 application/test files; inbound, Monitor, and outbound/messaging contracts; full route registration (221 routes). The outbound contract checks route/input schemas, tenant-view rewriting, signatures, public/private IP handling (including mapped IPv4), and secret encryption/AAD.
- Blocker: MariaDB at `127.0.0.1:3307` remains unavailable. As a result, no live event fan-out, webhook HTTP delivery/retry, messaging DB workflow, tenant-isolation integration test, or frontend-to-PHP end-to-end test has been run. External WhatsApp send/connect also requires configured credentials. Outbound and Messaging remain `IN_PROGRESS`; no feature-parity completion is claimed.

### 2026-10-17 — Agency comparison parity and live workflow verification
- Corrected the earlier environment notes: MariaDB 10.4 is listening on `127.0.0.1:3307` (separate from the MySQL 8 listener on 3306). Tests ran only against newly created `anibros_crm_feature_tests_*` schemas; no existing schema was cleared or dropped. Partially seeded schemas from failed test iterations were retained.
- Ported the React agency comparison report to the PHP service composition: same caller date window, per-client dashboard/report/attribution calculations under each authorized client actor, role scope/exclusions, same-currency aggregation, first-reply/deal/money metrics, and aligned trend buckets.
- Reconciled agency API mismatches against `src/api/agency.ts` and the React routes: added `/unread` and `/staff/invitations`, accepted the `agencyRole` patch field, returned result payloads from staff/client unassign deletes, and retained the legacy `/leads/unread` and `/staff` routes. Fixed `inviteStaff()` to use `Conn::one()` rather than the nonexistent `Conn::val()`.
- Replaced the incomplete agency lead-list SQL with the existing tenant lead-filter helper, bringing status/state, owner, source, tag, score, date, test, SLA, follow-up, custom-field and claim-pool filters into the merged list. Added accurate cross-client totals/sorting, shared SLA calculation, touch counts, query validation, and own-scope enforcement.
- Ported agency activity summaries to the React contract: date defaults and boundaries use the caller's time zone, ranges are capped, and the team activity query uses effective all/own assignment scope. Corrected the agency task feed's cutoff to use each client's local end-of-day and normalized due dates to UTC ISO strings.
- Extended `tests/database_workflows.php` with database-backed comparison-report, filtered lead-list/claim-pool, employee own-scope, caller-timezone activity, client-timezone task cutoff, persisted agency-HQ invitation, route/schema, and existing inbound/outbound/messaging/export/isolation assertions.
- Verification passed on `anibros_crm_feature_tests_20261017_agencyreport9`: migration, `tests/database_workflows.php`, PHP syntax checks, `tests/inbound_contract.php`, `tests/outbound_messaging_contract.php`, and `tests/monitor_contract.php` (224 API routes registered). Workflow evidence includes signed inbound receipt/rejection/idempotency and lead creation; signed outbound HTTP 503 then durable retry to HTTP 200; queued email through a local test transport plus opt-out rejection; agency lead filters/own scope, timezone-aware tasks/activity, CSV/XLSX and report data; agency invitation persistence; exact agency report/unread route registration; and cross-tenant read/write isolation.
- The React source remains unchanged. Agency, inbound, outbound, messaging, and overall CRM parity remain in progress: full role-matrix/browser workflows and real Meta/WhatsApp provider credentials are not covered by these tests.

### 2026-10-17 — Restored local UI/API connection and fixed agency team invitation listing
- Diagnosed the screenshot's “can't reach the server” state: port `8765` was stopped, and the former server session had also used a nonexistent database schema. Verified the React API base path was correct. Restored the PHP server on loopback against the existing isolated `anibros_crm_feature_tests_20261017_google1` schema; authenticated UI/API checks loaded leads, agency overview, clients, report, activity, tasks, staff, and realtime endpoints with HTTP 200.
- Browser testing exposed a separate agency Team API defect. `AgencyService::staff()` queried workspace table `tenant_invitations` through `Db::asUser()`, which correctly rejects workspace data without tenant context. Switched only that read to the agency-HQ `Db::tenant()` context and added a database workflow assertion that the staff listing returns the active invitation.
- Verification: `bin/migrate.php` applied `000_schema` and generated 76 isolation views on new isolated schema `anibros_crm_feature_tests_20261017_stafffix3`; `tests/database_workflows.php` passed, including the new staff-list regression assertion and all existing inbound/provider mock, outbound retry, messaging, agency scope, and cross-tenant checks. Browser verification against the retained `google1` schema showed the Team table, active invitation, and All leads page rendered; nine authenticated core agency/auth/realtime API requests returned HTTP 200.
- Kept the PHP UI/API server available at `http://127.0.0.1:8765/`; updated only the session-local router to disable opportunistic job pumping during UI tests. Before that change, its prior pump retried one queued fixture webhook job in the existing isolated `google1` test schema; this was visible in the PHP log, and the pump is now disabled to prevent further changes from browser requests. No existing schema was reset or dropped, the configured main CRM database was not selected, and no original React files were changed. Full module/role browser coverage, live external provider credentials, and a shared-host cPanel environment remain unavailable/unverified.
### 2026-10-17 — Fixed Contacts list and Automation browser regressions
- Fixed a MariaDB syntax error in `ContactsService::COLUMNS`: `GROUP_CONCAT` requires a literal separator, so alternate e-mail/phone aggregation now uses `0x0A` for the newline expected by the existing DTO.
- Added a database workflow assertion that the Contacts list returns populated data with alternate identifier fields as arrays. Added the React-compatible `/api/v1/automations` mount without removing `/api/v1/automation`, and fixed Automation correlated subqueries to reference `automation_rules ar`.
- Verification on newly created isolated schema `anibros_crm_feature_tests_20261017_contactsfix1`: migration, `tests/database_workflows.php`, inbound, outbound/messaging, Monitor, validation, and agency-assignment contracts passed; changed PHP files passed syntax checks and `MIGRATION_TRACEABILITY.json` parsed. Authenticated browser checks verified the Contacts API returns HTTP 200 with four records and correctly shaped alternate identifier arrays, the Contacts page has no load error, Automation template/executions requests return HTTP 200, and all 20 checked owner client routes rendered with no API response >=400.
- No existing schema/data was reset or removed, the local React source remains unchanged, and the existing local PHP UI/API server remains available. Full role/CRUD parity, live Meta/Google/WhatsApp credentials, complete cPanel deployment, and full visual parity remain unverified.
- A synthetic sales-agent browser check authenticated in an isolated client tenant and showed one assigned lead; Leads and Contacts rendered with no API failures. The owner was re-authenticated and Contacts was verified again. This does not complete the role matrix.
- All six checked-in PHP test scripts passed: `database_workflows.php`, `inbound_contract.php`, `outbound_messaging_contract.php`, `monitor_contract.php`, `validation_contract.php`, and `agency_assignment_contract.php`. In the workflow schema, five synthetic inbound events measured 30, 85, 96, 115, and 848 ms from receipt to processing (p50 96 ms, p95/p99 848 ms); this mock-driven local sample does not establish production or cPanel latency.

### 2026-10-17 — Browser-verified website lead receipt and UI visibility
- Validated the pending `Receiver.php` persistence-timing change: `php -l app/Modules/Inbound/Receiver.php` and `php tests/inbound_contract.php` passed. The receiver logs `persistMs` only after `InboundService::receive()` commits and before acknowledging the request.
- On retained isolated schema `anibros_crm_feature_tests_20261017_workerrecover2`, the unchanged compiled React UI submitted website leads to the PHP hook. The receiver returned HTTP 200 with `accepted: 1`; MariaDB recorded the event, and direct invocation of the existing inbound processor produced `status=created` and a non-null `lead_id`. The browser `/api/v1/leads` request returned HTTP 200 and the Leads list rendered the persisted unique lead without an error.
- A repeated-contact test was correctly classified `duplicate` and linked to the existing open lead, matching the deduplication workflow. The final distinct event's receipt was 2026-10-17 02:29:51.009 UTC, processing completed at 02:29:56.413 UTC, and the lead was visible in the browser at epoch 1792204206173 (9.760 s after processing). This is a single local observation; the processor was invoked manually because the session test router disables opportunistic worker pumping, and the elapsed interval includes test orchestration. It is not worker/SLO/production latency evidence.
- Structured receipt logs for three browser POSTs reported transaction times of 6.638, 6.779, and 6.906 ms. These are individual local measurements, not a percentile or hosting guarantee.
- No schema or source data was reset/deleted. Removed the one-off test helper and stopped only the temporary `:8766` UI/API server; the pre-existing `:8765` server was not stopped. Live provider credentials, actual cPanel deployment, scheduled-worker-to-UI latency, and full role/module parity remain unverified.

### 2026-10-17 — Actual cron-worker, concurrency, retry, and recovery verification
- Inspected `files/php-crm-test-router.php`: it sets `pump_after_response=false` only for the local web-test router. `Config` defaults the setting to `true`; `bin/worker.php` independently calls `Worker::pump(55.0, 100)`, and the cPanel command documented above schedules that entry point with `* * * * *`. The disabled HTTP after-response pump did not disable the CLI worker.
- Created additive isolated schema `anibros_crm_feature_tests_20261017_asyncworker1`, applied `bin/migrate.php` (`000_schema`, 76 isolation views), and left all previous databases unchanged. A logged-in browser on `:8766` posted a fresh website lead (HTTP 200 in 85 ms). MariaDB showed the event and queued job committed before worker execution. `Receiver.php` logged `persistMs=9.72`.
- A timer waited for the next UTC minute boundary and invoked the actual `bin/worker.php` entry point; output: `claimed=1, succeeded=1, retried=0, dead=0`. The worker began at 02:48:00.872 UTC. The lead row was inserted at 02:48:01.016, inbound `processed_at` committed at 02:48:01.038, and the unchanged, already-open React Leads page updated via realtime polling at 02:48:06.321 after `/api/v1/leads` returned 200 (no reload). From `received_at=02:47:13.433`, receipt-to-lead-row was 47.583 s, receipt-to-event-completion 47.605 s, and receipt-to-browser 52.888 s. This is one cron-equivalent timer sample, not an installed OS/cPanel cron test. The actual one-minute cron schedule therefore cannot guarantee seconds-level delivery; the production opportunistic pump is not yet verified on cPanel.
- Two simultaneous browser POSTs with the same endpoint/event ID both received HTTP 200; MariaDB contained one event and one `inbound.process` job, and the worker/UI showed one resulting lead. A batch of 12 unique simultaneous submissions received eight HTTP 200 acknowledgements and four explicit HTTP 429 responses from the website source's eight-token burst limiter; only the eight acknowledged events persisted. Two actual `bin/worker.php` processes started 21 ms apart claimed 5 and 3 queued jobs; all eight inbound jobs reached `done`, eight different tenant leads were persisted, and all eight became visible on the browser page with no load error.
- Simulated a worker crash after claim using a final-attempt expired lease, then ran `bin/worker.php`. The worker reaped the job to `dead`; inbound reconciliation changed the event to `failed` with a visible reason. The authenticated `/api/v1/lead-sources/events/{id}/retry` route returned HTTP 201; another actual worker run processed the retry and created one lead visible via polling. An initial test-fixture lease used the MySQL CLI's local `NOW()` time and was therefore in the future to PHP's UTC PDO session; corrected the fixture with `UTC_TIMESTAMP()` before accepting the recovery result. This was a test data correction, not an application code change.
- Verified the retry path through the actual worker using the existing local signed webhook fixture: an outbound delivery returned HTTP 503, the worker logged the retry and left a durable queued job, then after changing only the isolated fixture target to return HTTP 200 and making the retry due, the next worker invocation marked the delivery `delivered`, attempt 2, and job `done`. This is a local protocol fixture, not a live third-party endpoint.
- Queried the isolated schema after the test: zero inbound endpoint/event tenant mismatches, zero inbound event/lead tenant mismatches, worker heartbeat `healthy`, and no queued/running jobs. Paused the local-only outbound fixture endpoint after its success case and used the real worker to drain remaining automation jobs; retained the intentional dead-letter used to test worker interruption.
- The `:8766` PHP UI/API server is left running against the new isolated schema; `/api/health` returned 200 and the screenshot's `/tasks` page loaded with HTTP 200 task and summary APIs. The old `:8765` process was not stopped. Temporary helper PHP files and the local receiver secret were removed. The new schema and its synthetic evidence data were retained.
- No authorized cPanel account or live Meta/Google/WhatsApp credentials were available. Consequently, actual cPanel cron installation, FPM `fastcgi_finish_request` behavior, external network delivery, live provider callbacks, and a production seconds-level delivery SLO remain unverified; parity remains in progress.