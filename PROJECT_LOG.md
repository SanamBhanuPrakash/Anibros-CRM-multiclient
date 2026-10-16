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
