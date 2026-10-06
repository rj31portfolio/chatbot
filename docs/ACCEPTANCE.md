# Acceptance record

Recorded on 2026-10-06 for the local Laravel implementation. The application is available at http://localhost:8000 with MySQL 8.4, a database queue worker, and the scheduler running.

## Executed checks

- SQLite: 51 tests passed, 265 assertions, exit code 0.
- MySQL 8.4: the same 51 tests passed, 265 assertions, exit code 0, in a separate database with a restricted test user.
- Laravel Pint: passed.
- Production frontend build: passed.
- Blade template compilation: passed.
- Chrome browser acceptance: passed registration, business setup, service and FAQ training, widget configuration, generated embed code on a separate HTML website, mobile Shadow DOM isolation, conversation fallback, consented lead capture, CRM status and notes, human reply delivery, appointment request and confirmation, dashboard pages, and mobile navigation. No browser JavaScript errors were observed.
- Local login page: HTTP 200. Local owner and admin accounts are seeded with random passwords, stored only in ignored local credential files.

The browser test uses an isolated SQLite database. The main app uses MySQL. Provider and payment tests use controlled HTTP responses or injected adapters. Passing those tests demonstrates application behavior under those responses; it does not verify a live provider or gateway account.

## Required 34-step scenario

| Step | Required action | Evidence and remaining verification |
| --- | --- | --- |
| 1 | Create Super Admin | Interactive CLI tested; actual local admin seeded. |
| 2 | Login | Authentication tests and browser owner login; local admin login verified separately. |
| 3 | Create subscription plan | Admin CRUD and editable-limit tests. |
| 4 | Create business | Browser and database tests. |
| 5 | Business owner logs in | Browser and authentication tests. |
| 6 | Add business information | Browser setup; profile knowledge persists. |
| 7 | Add website URL | Browser setup and crawler fixtures. |
| 8 | Crawl website | Controlled public HTTP fixtures, robots rules and private-address rejection tested; a live customer crawl still needs an approved public website. |
| 9 | Generate knowledge | Crawler, catalog and upload parsing tests verify chunks and searchable content. |
| 10 | Add services | Browser and database tests. |
| 11 | Add FAQ | Browser and database tests. |
| 12 | Configure AI | Settings persist; provider configuration/context tests. Live DeepSeek key absent. |
| 13 | Configure widget | Browser and domain/plan tests. |
| 14 | Generate widget code | Browser extracts actual installation snippet. |
| 15 | Install on HTML website | Separate customer test server embeds the generated code. |
| 16 | Open website | Desktop/mobile Chrome. |
| 17 | Chat with AI | Browser verifies honest unknown-answer fallback; provider request/response integration tested with fixtures. Live DeepSeek conversation remains pending. |
| 18 | Ask business-specific question | Retrieval/provider tests; live conversation pending. |
| 19 | Retrieve correct business information | Tenant-scoped retrieval tests on SQLite and MySQL; live provider answer pending. |
| 20 | Ask pricing question | Pricing retrieval and disclosure-setting tests; live answer pending. |
| 21 | Use configured pricing | Provider context includes actual configured service pricing; live answer pending. |
| 22 | Show buying intent | Intent/scoring tests; live interaction pending. |
| 23 | Ask qualification questions | Sales prompt and structured extraction tests; live conversational quality pending. |
| 24 | Give name | Browser contact form and validated natural extraction tests. |
| 25 | Give phone | Contact/extraction and scoring tests. |
| 26 | Create lead | Browser and database tests. |
| 27 | Calculate score | Configurable weights, temperature and maximum-score tests. |
| 28 | Show in CRM | Browser verifies stored lead, status changes and notes. |
| 29 | Generate summary | Queue dispatch and provider-summary fixture tests; live provider summary pending. |
| 30 | Notify business | Database notification and queued mail tests; SMTP delivery pending. |
| 31 | Admin sees AI usage | Persisted usage and protected reporting routes tested; live provider usage pending. |
| 32 | Admin sees estimated cost | Metering/cost records tested; operator must configure current rates and currency conversion. |
| 33 | Enforce subscription limits | Conversation, token, document, API and agency quota tests; expiry and failed-call refunds tested. |
| 34 | Block cross-business access | Tenant query scopes, missing context, forged IDs, session/widget binding, permissions and API-key isolation tested. |

## Release status and outstanding scope

**The full production acceptance scenario has not passed.** Live DeepSeek answers, captured Razorpay payments, SMTP delivery, public deployment, backups and operational load remain unverified. Docker is supplied but could not be executed because Docker is unavailable here. No deployment or external messages were sent.

The implemented billing flow sells fixed monthly/yearly periods; it does not provide recurring mandates, automatic overage charging, refunds, gateway webhook reconciliation or proration. Coupon uses are reserved at checkout creation, including abandoned checkouts.

Further requested product work includes the complete guided onboarding wizard, advanced proactive and pageview rules, visitor chat attachments, widget avatar/theme/language controls, expanded visitor/source reporting, AI tag suggestions, a general condition/action automation engine, and platform maintenance controls. Existing simple automation rules, uploads in owner training, branding settings and analytics are functional; they should not be confused with those broader features.

OpenAI/Gemini/Anthropic adapters, embeddings, calendar integrations, advanced WhatsApp and custom white-label domain provisioning are extension work. The provider and tenant architecture supplies the interfaces for these additions.

See [README](../README.md) for setup, credentials, commands and integrations, and [architecture](ARCHITECTURE.md) for implementation boundaries.
