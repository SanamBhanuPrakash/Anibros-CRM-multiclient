# ANIBROS CRM — AUTONOMOUS OVERNIGHT MIGRATION AND PRODUCTION-QUALITY COMPLETION

## MISSION
You are taking ownership of the final engineering execution of the Anibros Design multi-tenant CRM migration.

Operate simultaneously as a Principal Software Architect, Principal PHP Engineer, React/TypeScript Engineer, Database Architect, API Integration Engineer, Application Security Engineer, SRE, QA Automation Lead, and Production Readiness Reviewer.

You are expected to engineer to a high production standard comparable to a mature engineering organization. Think critically, trace behavior to its source, challenge assumptions, and verify your work.

**The objective is to deliver the same Anibros CRM on PHP + MySQL/MariaDB for shared cPanel hosting, preserving its original UI, architecture's intended behavior, business logic, integrations, and security guarantees.**

This is not a request to create a plan, write more documentation, or produce another audit without implementing code.

You must continue the actual migration, test the implementation, fix the defects you find, and leave the repository in a coherent, usable state.

The owner will be away and cannot supervise individual decisions or approve routine engineering steps. Proceed autonomously within the safety boundaries defined below.

---

# 1. PROJECT IDENTITY — VERIFY BEFORE EDITING
The verified project locations from the previous session are:

**Original React/Node.js/PostgreSQL reference — READ ONLY**

`D:\work\Xampp\htdocs\Anibrosdesign-CRM`

**PHP migration target**

`D:\work\Xampp\htdocs\Anibros-CRM-multiclient`

**Known local PHP runtime**

`D:\work\Xampp\php\php.exe`

**Target technology**

- PHP 8.1+ or the actual supported production version.
- MySQL/MariaDB with PDO.
- HTML, CSS, JavaScript, and the existing React frontend where appropriate.
- cPanel-compatible cron and background processing.
- SMTP and provider APIs through explicitly configured credentials.
- No mandatory Node.js server running in production.
The project has already received substantial migration work. The previous execution report recorded:

- A PHP implementation of the Monitor API.
- PHP inbound lead-source/webhook functionality.
- PHP outbound webhook and messaging functionality.
- Agency reporting, tenant-scoped lead filtering, cross-client sorting and totals, employee own-scope, activity summaries, task timezone boundaries, staff invitations, and exports.
- Database-backed workflow tests passing against the fresh test schema `anibros_crm_feature_tests_20261017_agencyreport9`.
- Inbound, outbound/messaging, and Monitor contract tests passing.
- 224 API routes registered.
- PHP syntax checks and parity/project-log updates.
- Remaining gaps in actual Meta/WhatsApp provider workflows, browser-level UI/download flows, and broader module/role coverage.
These are historical claims, not assumptions about the present state. **Inspect the current repository, test results, changes, and running commands to establish the actual state. Do not repeat completed work unnecessarily.**

There was also a background command running in the previous execution. Check its actual status and output before launching overlapping work.

Do not assume that the database is currently unavailable just because it was unavailable in an earlier session. Verify the current environment.

If any directory differs from the paths above, identify the real source and target before editing.

# 2. FIRST REQUIREMENT: INGEST THE ENTIRE INSTRUCTION
This instruction is deliberately comprehensive. Do not silently truncate, forget, or replace its requirements with a brief summary.

Before executing:

1. Capture the complete requirements as a durable execution contract in a suitable file under `docs/`, preserving every numbered section and mandatory requirement.
2. If your coding-agent environment or context window risks truncation, use Python to split the saved contract into numbered, overlapping chunks. Read and process every chunk, then verify that all section IDs were covered. Reassemble or cross-check the entire contract before implementation.
3. Create a concise, machine-readable traceability checklist mapping requirements to implementation files and tests. Use Python for inventory processing or comparison where it improves reliability.
4. Maintain a durable checkpoint containing completed tasks, outstanding tasks, test results, and the exact next action.
Do not spend the session merely creating these documents. This is a safeguard against information loss; **the main work is implementing and verifying the CRM**.

Never claim that you processed an entire inventory, file, or prompt if it was truncated or inaccessible.

# 3. ABSOLUTE PRODUCT REQUIREMENTS
The original React CRM is the product specification.

Preserve:

- The complete user interface and its visual design.
- All existing routes, pages, layouts, responsive behavior, forms, tables, filters, charts, modals, dropdowns, drawers, notifications, and interactions.
- Every original business capability and workflow.
- All API contracts and relevant event formats.
- Authentication, roles, permission rules, and agency/workspace behavior.
- Multi-tenant isolation and ownership rules.
- Google, Meta, webhook, messaging, website form, chatbot, email, and analytics integrations that exist in the source.
- Existing automations, background processing, reporting, audit history, and operational diagnostics.
- Validation, error handling, loading states, empty states, and recovery behavior.
- The intended concurrency, consistency, and idempotency guarantees.
Do not simplify an implementation because its original behavior is complicated.

Do not remove a capability because it is difficult to port to PHP.

Do not replace a real integration with a mock and declare it complete.

Do not change the UI merely because recreating it is easier than connecting it to the new backend.

Do not implement only the happy path and ignore retries, failures, permissions, and concurrent requests.

Every source feature must remain traceable to a real PHP implementation and supporting evidence.

# 4. PRESERVE THE ORIGINAL UI
Inspect the actual React source, package configuration, routing, API clients, shared components, build output, static assets, CSS, fonts, design tokens, and responsive behavior.

The preferred approach is to **reuse the original React frontend and compiled assets unchanged wherever possible**, connecting them to the PHP API through compatible routes and contracts.

A React frontend can run as static HTML, CSS, and JavaScript assets on cPanel. The backend can be PHP. The goal is to eliminate the required Node.js server from production, not to needlessly rewrite a carefully designed frontend.

Preserve the original:

- Branding, typography, colors, dimensions, spacing, alignment, and layout.
- Sidebar, navbar, navigation hierarchy, breadcrumbs, and menus.
- Charts, metrics, tables, badges, and dashboard components.
- Theme behavior and responsive breakpoints.
- Modal, form, dropdown, filter, pagination, and selection behavior.
- Button labels, icons, hover/focus states, and keyboard interactions.
- Error messages, success feedback, loading indicators, and empty states.
- Direct routes, deep links, refresh behavior, and browser history.
- Role-specific and tenant-specific UI states.
If frontend source changes are genuinely necessary to connect the API, make the smallest compatible change possible and demonstrate that it does not alter the visual design or workflow.

Do not modify the protected reference project.

Verify the actual frontend build tools and production output. Do not introduce a Node.js production dependency merely because the development build requires Node.

# 5. ORIGINAL ARCHITECTURE IS THE AUTHORITY
Inspect the original project completely enough to understand the real architecture before porting individual features.

Trace:

- Frontend components to API clients.
- API routes to controllers and services.
- Service methods to business rules and database operations.
- Provider callbacks to event ingestion and processing.
- Database schema and migrations to application behavior.
- Cron jobs, workers, event handlers, retries, and side effects.
- Authentication and authorization across every request.
- Website integrations and external clients to their actual API contracts.
- Configuration and deployment requirements.
Review all existing documentation, including `ARCHITECTURE.md`, API documentation, migrations, tests, deployment notes, and `MIGRATION_PARITY.md`.

Inventory all source routes, endpoints, entities, modules, background jobs, integrations, configuration keys, and tests. Do not rely on an old endpoint count as proof that the inventory is complete.

Where the previous implementation introduced legitimate cPanel-compatible adaptations, retain them unless they are incorrect or insecure.

Do not redesign the whole architecture or replace working components without evidence. Follow the existing design decisions unless a concrete defect requires a change.

When PHP differs from Node.js or PostgreSQL, introduce the smallest maintainable, behaviorally equivalent adapter.

# 6. META, GOOGLE, WEBHOOKS, WEBSITE FORMS, AND CHATBOT
These are critical business capabilities, not optional integrations.

Inspect the actual provider implementations, configuration, routes, database tables, frontend screens, scripts, callbacks, event formats, and tests in the original project.

## A. Meta Lead Ads
Verify the complete implementation against the original application:

- Webhook verification challenge and callback configuration.
- Signature validation using the provider-prescribed mechanism.
- App secrets, access tokens, permission requirements, and account/page associations.
- Lead-event payload parsing and field mapping.
- Lead retrieval through the appropriate official API where required.
- Integration-to-tenant mapping and authorization.
- Duplicate webhook delivery handling.
- Idempotent lead creation and updates.
- Durable event ingestion and asynchronous processing.
- Retry behavior, terminal failures, logging, and replay.
- Token expiry and provider API failures.
- Integration health and diagnostic information.
Validate raw request bodies before parsing where signature verification requires it. Use constant-time comparisons when appropriate. Do not trust tenant IDs or integration ownership supplied in untrusted payloads.

## B. Google integrations
Identify exactly which Google services are used in the reference; do not conflate Google Ads, Google lead forms, OAuth, GTM, and GA4.

Preserve the actual integration workflows, including applicable:

- Google Ads lead-form integrations and supported lead sources.
- OAuth authorization, callback handling, scopes, and token refresh.
- Customer/account configuration.
- Lead payload parsing and field mapping.
- Provider response and error handling.
- Attribution and relevant conversion events.
- GTM, GA4, tracking configuration, and analytics behavior.
- Credential validation and diagnostics.
- Webhook authentication, retries, and duplicate-event handling.
Inspect the real source before deciding whether an integration uses OAuth, signed requests, a shared secret, or another authentication method.

Do not invent a Google signature-validation mechanism where the provider does not define one.

Use current official provider documentation when available, while preserving the application's configured API version and compatibility requirements. Do not upgrade provider API versions blindly.

## C. Outbound webhooks
Verify:

- Tenant-scoped endpoint management.
- Event subscriptions and filtering.
- Payload schemas and serialization.
- Request signing and secret rotation.
- Durable outbox persistence.
- Dispatch and delivery history.
- HTTP status and response recording.
- Timeouts and bounded retries with backoff.
- Idempotency and replay behavior.
- Failure recovery and administrative diagnostics.
- Protection against server-side request forgery, unsafe redirects, private network targets, and cloud metadata endpoints.
Do not allow user-controlled destinations to turn the CRM into an SSRF proxy.

## D. Website contact forms
Inspect the original website contact-form integrations and every existing endpoint, embed, script, field mapping, and lead-source convention.

The PHP CRM must preserve the existing website-to-CRM flow, including:

- Submission endpoint and accepted payload.
- Required and optional fields.
- Source and campaign attribution.
- Tenant/client routing.
- Field validation and normalization.
- Duplicate prevention.
- Safe public endpoint abuse controls.
- Success/error response behavior.
- Durable lead creation and audit history.
- Compatibility with the existing website.
Do not break a live website by changing its endpoint, request format, CORS expectations, field names, response shape, or submission behavior without a compatible migration.

If the source supports multiple websites or clients, preserve the exact tenant routing rules. Never infer a tenant solely from a spoofable payload field.

Test missing fields, invalid payloads, duplicate submissions, malicious input, oversized requests, unauthorized tenant targeting, and transient database failures.

## E. Chatbot and captured leads
Inspect the real chatbot-to-CRM flow and all existing code or integration contracts associated with it.

Preserve applicable:

- Conversation/session identity.
- Visitor and contact capture.
- Lead qualification and field mapping.
- Tenant/client resolution.
- Conversation and source attribution.
- Duplicate-session and duplicate-lead handling.
- Message submission and response behavior.
- Error handling and retry rules.
- Secure communication with CRM APIs.
- Existing assistant/widget behavior and UI.
Do not assume the chatbot is merely a public lead form. Trace how the original implementation represents conversations, users, messages, and lead conversion.

Verify the website integration and chatbot end to end against the PHP target.

## F. WhatsApp and messaging
Inspect the current provider integration and the existing PHP messaging implementation.

Preserve provider-supported sending, receiving, authentication, callback verification, status updates, message IDs, delivery reports, retries, failure handling, conversation mapping, contact association, and tenant isolation.

Test template and text-message behavior where supported. Verify phone number normalization, recipient validation, and provider-specific requirements.

Never send real messages to customers during automated testing. Use deterministic provider mocks, sandbox/test numbers, or an explicitly authorized test environment.

A successful mocked test must be labeled as mocked. It does not prove that the live provider credentials, application permissions, callback configuration, or account setup work.

# 7. RELIABILITY AND EVENT PROCESSING
Inspect the existing PHP queue, jobs, events, transaction utilities, outbox, inbox, and workers.

Preserve reliable processing under duplicated deliveries, retries, concurrent requests, transient errors, and interrupted workers.

Required properties where applicable:

- Durable acceptance of events.
- Stable idempotency keys.
- Tenant-scoped processing.
- Atomic or otherwise safe worker claiming.
- Concurrency-safe state transitions.
- Bounded retries and backoff.
- Stale-job recovery.
- Failure visibility and controlled replay.
- No duplicate task, lead, message, or webhook side effects.
- Explicit delivery and execution outcomes.
Do not acknowledge an event before its durable persistence if doing so could lose the event and the provider protocol allows a safer sequence.

Do not confuse job execution with successful completion of the external side effect.

For email, webhook, messaging, and automation workflows, distinguish queued, processing, delivered/succeeded, retryable failure, and terminal failure according to the original semantics.

If the original runtime uses WebSockets or another service unavailable on shared hosting, preserve the existing UI through a compatible, authenticated polling or long-polling adapter. Use incremental cursors and prevent missed events, duplicate consumption, and cross-tenant leakage.

Document and test actual latency trade-offs. Do not claim unsupported real-time guarantees.

# 8. CORE CRM AND AGENCY FUNCTIONALITY
Do not focus exclusively on integrations and overlook the core CRM.

Derive the actual module inventory from the reference. Review every applicable area, including:

- Authentication and session management.
- Workspaces, agencies, clients, and tenant switching.
- Users, memberships, invitations, roles, and permissions.
- Dashboard, statistics, activity feeds, and notifications.
- Leads, sources, assignments, ownership, lifecycle, and conversion.
- Contacts, activities, tasks, and follow-ups.
- Property and project management, matching, availability, and viewings.
- Deals and sales pipelines.
- Search, filters, sorting, bulk actions, pagination, and exports.
- Custom fields and configuration.
- Automation rules, triggers, actions, scheduling, and execution history.
- Reports, attribution, and analytics.
- Email, WhatsApp, communication history, and delivery status.
- Integrations, webhook diagnostics, and administrative controls.
- Audit logging and monitoring.
- System settings, client settings, and user preferences.
This is a discovery checklist, not a substitute for source inspection. Add any modules or edge cases missing from this list but present in the original system.

The recent AgencyService and AgencyRoutes implementation must be regression-tested for tenant-scoped filtering, cross-client totals and sorting, employee own-scope, timezone boundaries, staff invitations, exports, and response compatibility.

Verify that API payload changes match the exact frontend client expectations.

# 9. DATABASE AND TENANT SECURITY
The CRM is multi-tenant. Treat tenant isolation as a hard security boundary.

Inspect and test every database table, foreign-key relationship, query helper, tenant view, transaction, migration, unique constraint, and authorization pathway.

Test:

- Cross-tenant record access and mutation.
- Guessable IDs and forged workspace IDs.
- Nested relationships and joins.
- Search, autocomplete, dashboards, aggregates, reports, and exports.
- Bulk actions and file access.
- Webhook configuration and events.
- Background jobs and cross-tenant message delivery.
- Role escalation and employee own-scope restrictions.
- Invitations and tenant membership changes.
- Concurrent inserts and duplicate submissions.
Use prepared statements, explicit writable-field allowlists, database constraints, transactions, and server-side authorization.

Do not rely exclusively on UI hiding or application conventions to enforce permissions.

The MySQL/MariaDB design must reproduce the required PostgreSQL behavior deliberately, including transaction semantics, UUID handling, JSON fields, date/time behavior, collation, and uniqueness requirements.

Never reset, drop, truncate, or overwrite existing application data. Do not execute destructive migrations automatically.

Use an isolated test schema and uniquely named test records. Preserve existing schemas and user data.

Test migrations and recovery in isolation before recommending any production migration.

# 10. SECURITY REVIEW
Perform a practical, code-based security review focused on vulnerabilities that can affect this CRM.

Cover at least:

- Broken access control and IDOR.
- Cross-tenant leakage.
- SQL injection and mass assignment.
- XSS and unsafe output rendering.
- CSRF and session weaknesses.
- Authentication, reset, invitation, and token lifecycle.
- Webhook spoofing, replay, and duplicate processing.
- SSRF, unsafe outbound URLs, and redirects.
- File upload/download access and path traversal.
- Brute force, abuse, and request-size limits.
- Secret exposure in source, bundles, logs, responses, or backups.
- Unsafe production defaults and verbose errors.
- Race conditions and lost updates.
- Insecure cron/worker invocation.
- Cross-tenant cache leakage.
- Unsafe exports and spreadsheet formula injection.
Use the existing PHP application's established security abstractions when they are sound. Fix root causes rather than introducing unrelated parallel implementations.

Never log access tokens, passwords, session IDs, provider secrets, or encryption keys.

Do not add production backdoors, hard-coded administrator accounts, default passwords, permissive authorization fallbacks, or debug bypasses.

# 11. CPANEL PRODUCTION COMPATIBILITY
The target must be deployable on ordinary shared cPanel hosting.

Verify the actual hosting requirements rather than assuming a particular provider capability.

Keep the production runtime to supported PHP, MySQL/MariaDB, static frontend assets, HTTP APIs, SMTP, and configured cron jobs.

Verify:

- Actual PHP version and required extensions.
- PDO MySQL support.
- Database permissions and connection settings.
- HTTPS, session cookies, and environment configuration.
- Correct frontend asset paths and MIME types.
- API routing and client-side deep links.
- Uploads and protected downloads.
- SMTP configuration.
- Cron invocation, worker locks, retries, and execution limits.
- Logging, disk usage, and resource limits.
- No Node.js server requirement at runtime.
- No required Redis, Docker, or unavailable persistent service.
- Backup and restoration requirements.
If the target uses a frontend build step, verify whether it is needed only during development/deployment or is improperly required at runtime.

Keep sensitive environment files, application logs, backups, private uploads, and credentials outside publicly accessible locations wherever the hosting layout allows.

Document only environment requirements that are actually needed by the implementation.

# 12. AUTONOMOUS TESTING
Do not merely generate test files. Execute the tests and inspect their results.

First inspect the currently running background command and poll it to completion if appropriate. Do not launch a duplicate long-running test suite unnecessarily.

Run the existing database-backed workflow and contract tests before making broad changes. Record the baseline. Then work through the uncovered gaps.

Use the existing test framework and browser-testing tools if available. Avoid introducing large dependencies just to obtain a report.

### Backend verification

- PHP syntax checks across the affected and, where practical, all application PHP files.
- API route registration and route inventory reconciliation.
- API contract tests.
- Database-backed workflows on an isolated test schema.
- Existing inbound, outbound/messaging, Monitor, and Agency regression tests.
- Authentication, RBAC, and tenant-isolation tests.
- Migration/schema consistency checks.
- Worker, retry, and idempotency tests.

### Browser verification
Use the existing browser testing environment if available. Connect the unchanged React frontend to the PHP backend.

Exercise:

- Login and session restoration.
- Navigation and direct route refresh.
- Lead and contact creation, editing, filtering, and assignment.
- Agency/client switching and permission-restricted behavior.
- Forms, modals, dropdowns, and tables.
- Tasks, activity, reports, and exports.
- Website/contact-form submission where the integration exists.
- Chatbot capture and the corresponding CRM lead workflow.
- Integration management and webhook diagnostics.
- Messaging workflows using safe test providers.
- Loading, empty, success, and failure states.
- Browser downloads and exported data.
- Mobile/responsive layout and console/network errors.
Compare screenshots against the original UI using equivalent viewport size, theme, role, and data. Fix visual regressions caused by the migration.

### Provider verification
When genuine test credentials or an authorized test environment are available, execute real provider tests carefully.

When credentials are unavailable, use provider-compatible deterministic mocks and contract tests. Record exactly what is mocked and what remains unverified.

Never fabricate a successful live Meta, Google, WhatsApp, SMTP, or webhook-provider test.

Never send live messages, create unintended customer records, modify real ad configurations, or trigger production side effects merely to produce a green test.

### Failure handling
For every failing test, determine whether the cause is a real regression, an environment dependency, invalid test assumptions, or an existing source limitation.

Do not delete tests, weaken assertions, or suppress exceptions to make the suite pass.

Fix genuine regressions. For blocked checks, provide the precise external dependency or configuration required.

# 13. VISUAL AND FUNCTIONAL PARITY REGISTER
Maintain the existing `docs/MIGRATION_PARITY.md` and `PROJECT_LOG.md`.

Track every source feature and applicable endpoint against its PHP counterpart.

Use evidence-based statuses such as:

- `NOT_INVENTORIED`
- `INVENTORIED`
- `IMPLEMENTED`
- `VERIFIED`
- `BLOCKED`
- `NOT_APPLICABLE_WITH_EVIDENCE`
Record implementation files and test evidence.

Do not mark a feature verified merely because its page loads, the PHP syntax is valid, an API returns HTTP 200, a route exists, or a mocked integration returns success.

Separate visual parity, API compatibility, business behavior, database persistence, security, provider integration, and deployment readiness.

Reconcile the original source inventory against the PHP target. Do not use the current count of 224 API routes as proof of completeness.

Every unexplained missing route, module, field, action, provider contract, or security condition must remain visible.

# 14. WORK ORDER — IMPLEMENT, TEST, FIX, CONTINUE
Execute in this priority order:

**Priority 1: Recover current execution state.** Inspect the running background command, current git status, target files, PHP runtime, database state, latest workflow test, and existing changes. Do not overwrite any work.

**Priority 2: Establish a clean baseline.** Run the relevant existing tests and inspect the actual failures. Preserve all databases and the original source.

**Priority 3: Close the highest-risk integration gaps.** Complete and verify Meta lead intake, Google lead intake, webhook signatures and processing, website contact-form linking, chatbot capture, outbound delivery/retries, and WhatsApp workflows according to the original source.

**Priority 4: End-to-end browser workflows.** Connect the original UI to PHP, verify business flows and downloads, test permissions, and fix API/client mismatches without redesigning the UI.

**Priority 5: Full module and role parity.** Compare every original module, route, API, and role condition against the target. Implement missing functionality in dependency order.

**Priority 6: Security and concurrency regression testing.** Verify tenant boundaries, permissions, duplicate processing, race conditions, retries, and error handling.

**Priority 7: Production packaging.** Verify cPanel compatibility, configuration, cron, deployment instructions, and safe migration/recovery procedures.

**Priority 8: Final verification.** Re-run the relevant regression suites and confirm that the code, database workflows, frontend, and documentation agree.

Do not stop after any one priority while further feasible work remains.

Avoid repeatedly running the entire suite after every small edit. Use targeted tests during implementation, followed by broader regression runs at meaningful checkpoints.

If an entire test suite is expensive, parallelize only independent, safe checks. Never run conflicting migrations or the same mutable test schema concurrently.

# 15. AUTONOMOUS DECISION RULES
You have authority to make routine, reversible implementation decisions without asking questions.

You do not have authority to perform destructive or live-production operations without explicit approval.

**Permitted autonomously:**

- Read source files and documentation.
- Inspect configuration without exposing secrets.
- Modify PHP-target application code and necessary tests.
- Make focused, reversible compatibility fixes.
- Create isolated test schemas and test records.
- Run local test commands and browser tests.
- Use provider mocks or authorized sandboxes.
- Update migration and execution documentation.
- Fix demonstrated security and reliability defects.
**Never do autonomously:**

- Modify the protected React/Node/PostgreSQL source project.
- Drop, reset, truncate, or overwrite existing databases.
- Delete old test schemas or user data to save resources.
- Deploy to live cPanel, change DNS, or alter live provider configurations.
- Rotate or replace production credentials.
- Send unsolicited live WhatsApp/email messages.
- Modify real campaigns, budgets, customer records, or provider subscriptions.
- Commit or push changes without explicit authorization.
- Hide failures or mark unsupported behavior as verified.
Preserve all existing uncommitted work. Before editing a file with pre-existing changes, understand the diff and modify only what is necessary.

Never assume that a test database can safely be dropped just because its name contains `test`.

# 16. ENGINEERING QUALITY BAR
For every meaningful code change:

1. Understand the original behavior.
2. Identify the affected contracts and dependencies.
3. Implement the smallest reliable solution.
4. Add or extend a test reproducing the requirement or bug.
5. Run the relevant tests.
6. Inspect the result and correct any regressions.
7. Verify security and tenant scope.
8. Update the parity register with actual evidence.
Prefer readable, modular, maintainable PHP over clever abstractions. Follow the existing code style unless it contains a defect.

Avoid duplicated business logic, unnecessary dependencies, hidden fallback behavior, swallowed exceptions, excessive database queries, unbounded worker loops, and misleading success responses.

Use transactions where multiple database changes must remain consistent.

Treat retries, provider timeouts, null values, timezone boundaries, concurrent requests, duplicate events, and partial failure as normal engineering conditions, not rare exceptions.

Use official provider documentation and existing source contracts to resolve uncertainty. Never invent API capabilities or authentication requirements.

# 17. TIME AND CONTEXT MANAGEMENT
The owner is sleeping, so do not stop to request approval for ordinary implementation choices or ask routine clarification questions.

Use the available execution time effectively:

- Prefer concrete implementation and verification over long explanations.
- Keep durable progress checkpoints.
- Run long-running commands only when necessary and monitor their actual exit status.
- Resume interrupted work from the latest checkpoint.
- Do not waste time regenerating completed code or repeating broad audits without a reason.
- Do not leave an active background process unexamined.
- Do not claim work is continuing after the agent session has stopped.
If a context limit or session interruption occurs, save the exact completed state, outstanding tasks, failing command and result, affected files, and next concrete action. A subsequent run must resume from that checkpoint.

If a provider credential, external account, unavailable service, or hosting restriction prevents a particular test, continue implementing and validating the other feasible requirements. Clearly record the exact blocker instead of fabricating verification.

Prioritize correctness and safe completion over the number of files changed.

# 18. FINAL ACCEPTANCE CRITERIA
The migration is complete only when evidence demonstrates all applicable conditions:

- The original source remains unchanged.
- The PHP target is the application being developed and tested.
- The existing React UI has not been unintentionally redesigned.
- All original source modules and applicable endpoints are reconciled against the PHP implementation.
- All required business workflows work end to end.
- Database changes persist with correct tenant ownership and relationships.
- Roles and permissions are enforced server-side.
- Cross-tenant access tests pass.
- Meta and Google integrations preserve their real provider contracts.
- Website contact forms and chatbot capture create correctly scoped CRM records.
- Webhook ingestion and delivery are durable, authenticated, idempotent, and recoverable where required.
- WhatsApp and email workflows are tested within the available authorized environment.
- Background jobs and concurrent edits do not produce duplicate or corrupted business actions.
- Browser navigation, forms, reports, downloads, and exports work.
- The application can be deployed using verified PHP/MySQL/cPanel capabilities.
- Security, backups, migrations, cron, and operational requirements are documented and tested where feasible.
- The final regression tests have actual recorded results.
- No critical issue is hidden behind a green build or a successful mocked test.
- The parity register contains no unexplained omissions.
Do not equate route count, line count, or test count with completeness.

Do not call the application production-ready if a critical security issue remains or if fundamental workflows have not been verified.

# 19. FINAL ACTION — START WORK NOW
First inspect the running background command and the current repository state. Then execute the highest-value missing implementation task.

**Do not reply with another plan and stop. Do not produce only documentation. Do not wait for the owner to return.**

Continue the actual code migration, testing, debugging, and parity closure until the achievable work is complete or a concrete blocker prevents further progress.

When the execution window or available resources are exhausted, leave the repository in a coherent, recoverable state and write a concise final report containing:

1. Actual files and functionality changed.
2. Exact test commands and observed pass/fail results.
3. Real end-to-end provider tests versus mocked tests.
4. Confirmed browser/UI and database verification.
5. Any critical security or production-readiness issues.
6. The exact remaining work, ranked by risk and importance.
7. The next concrete task for continuation.
Be completely honest. Never claim that an integration, test, or feature works without evidence.

**FINAL PRINCIPLE: Preserve the React CRM's product design and established architecture while delivering a robust PHP/MySQL implementation that is secure, multi-tenant, integration-complete, testable, maintainable, and compatible with the actual cPanel environment. Build on existing work. Fix root causes. Never silently omit functionality. Never fabricate success.**