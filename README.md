# AI Lead Agent

A Laravel 13 SaaS application for business knowledge, DeepSeek-backed website chat, lead qualification, and CRM. PHP 8.3+, MySQL 8+, Blade, Alpine.js, and Tailwind CSS. The working local installation uses a project-local PHP 8.4 and MySQL 8.4; system installations were left unchanged.

## Open the local application

Visit **http://localhost:8000**. Demo owner credentials are in `storage/app/demo-credentials.txt`; local super admin credentials are in `storage/app/admin-credentials.txt`. These are randomly generated, ignored by Git, and are not created by production seeding.

The local MySQL server listens on `127.0.0.1:3308`. Application and test databases have separate users and grants. The application uses `ai_lead_agent_local`; automated MySQL tests use `ai_lead_agent_test`. The previous development SQLite file remains intact.

## Install on another machine

```sh
composer install
npm ci --ignore-scripts
cp .env.example .env
php artisan key:generate
```

Configure MySQL credentials and `APP_URL` in `.env`, then:

```sh
php artisan migrate --seed
npm run build -- --configLoader runner
php artisan app:create-admin
php artisan serve
```

Run these in separate terminals:

```sh
php artisan queue:work --timeout=900 --tries=3 --sleep=3
php artisan schedule:work
```

Database queue visibility is 960 seconds, longer than the crawler’s 900-second timeout. Production can run `php artisan schedule:run` every minute instead of `schedule:work`.

Optional local demo data:

```sh
php artisan db:seed --class=DemoSeeder
php artisan db:seed --class=LocalAdminSeeder
```

`DemoSeeder` is blocked in production. `LocalAdminSeeder` runs only in the local environment. Normal `migrate --seed` creates configurable plans and never creates a default user or admin password.

On this Windows workspace, replace `php` with `.\.tools\php\php.exe`. Run the SQLite suite directly with `& .\.tools\php\php.exe vendor\bin\phpunit` so a subprocess cannot select the older system PHP runtime. To restart the supplied MySQL runtime:

```powershell
& '.\.tools\mysql\mysql-8.4.11-winx64\bin\mysqld.exe' --no-defaults --basedir=D:/Working/chatbot/.tools/mysql/mysql-8.4.11-winx64 --datadir=D:/Working/chatbot/.tools/mysql-test-data --port=3308 --bind-address=127.0.0.1 --mysqlx=OFF --console
```

## Features connected to the application

- Registration, login, password reset, business setup, configurable industries, and permission-controlled teams.
- Business profile, services, products, FAQs, pricing, policies, manual knowledge, and queued PDF/TXT imports.
- A bounded website crawler with robots.txt checks, public-address validation, DNS pinning, HTML extraction, chunking, and plan limits.
- Business-scoped keyword and MySQL full-text retrieval, source tracking, a private tester, dynamic prompts, and an AI provider interface.
- DeepSeek HTTP integration, request metering, token reservation, failure refunds, cost logs, controlled history, and narrow static-answer caching.
- A Shadow DOM widget, exact approved domains, per-session bearer tokens, mobile chat, contact forms, appointment requests, and phone/email/WhatsApp links.
- Natural contact extraction, validated structured output, configurable scoring, lead CRM, notes, assignment, tags, and conversation history.
- Human replies delivered by polling; an owner reply pauses the AI. Closing or abandoning a conversation queues an AI summary.
- Dashboard notifications, queued email notifications, signed webhook deliveries with retries, and simple automation rules for tasks, tags, and email.
- Database-derived dashboards and analytics, CSV exports, and real PDF exports capped at 500 records.
- Configurable subscription plans and quotas; monthly/yearly Razorpay checkout with signature and captured-payment verification.
- Admin coupons, usage/cost reporting, subscriptions, user/business suspension, branding, revenue estimates, and currency-aware margin reporting.
- An agency workspace with separate client businesses and plan-controlled creation limits. Each client has a separate plan and usage allowance.
- Plan-controlled business branding and widget branding removal. Platform name, colors, logo, favicon, company details, and support email are configurable.
- Expiring, revocable Sanctum API keys with business binding and explicit permissions.

## Connect real services

DeepSeek:

```dotenv
DEEPSEEK_API_KEY=
DEEPSEEK_BASE_URL=https://api.deepseek.com
DEEPSEEK_MODEL=deepseek-chat
AI_INPUT_COST=0
AI_OUTPUT_COST=0
AI_RESPONSE_CACHE_SECONDS=600
```

Choose a model available to your DeepSeek account. Super admins can also set the model and encrypted API key in platform settings. Token prices are configurable USD estimates; zero means no rate was configured. The widget never receives API secrets. Missing credentials produce a friendly failure, not a simulated AI answer.

Razorpay:

```dotenv
RAZORPAY_KEY_ID=
RAZORPAY_KEY_SECRET=
```

Checkout purchases a prepaid access period. Automatic recurring mandates, gateway webhooks, refunds, proration, and usage overage charges are not implemented. Coupon uses are reserved when a discounted order is created; abandoned orders consume that configured checkout use. Subscription access starts only after a captured payment is verified.

Configure the `MAIL_*` environment variables for actual email delivery. The default log mailer writes to private application logs. Dashboard notifications work through the queue without SMTP.

## Install a widget

1. Create a business and add verified knowledge.
2. Configure AI behavior and test business-specific questions.
3. Configure the widget and approve the exact website hostnames.
4. Copy the script from the Installation page into your website’s footer.

```html
<script src="https://YOURDOMAIN/widget.js" data-widget-id="PUBLIC_WIDGET_UUID" defer></script>
```

Serve the customer test page over HTTP/HTTPS. `file://` and opaque origins are rejected. The customer’s CSP must allow the script and API origin. Widget rendering uses DOM text nodes and isolated CSS; customer CSS cannot restyle its controls.

The public widget identifier is not an authentication secret. Origin restrictions constrain browser embedding; the visitor token protects conversation access. Server clients can spoof Origin, so public endpoints also have IP and session limits and enforce subscription allowances.

## Business REST APIs

Create a key from **API keys** in your business workspace. Send:

```http
Authorization: Bearer YOUR_API_KEY
Accept: application/json
```

| Route | Permission |
|---|---|
| `GET /api/business/leads` and `/leads/{uuid}` | `leads` |
| `PUT /api/business/leads/{uuid}` | `manage_leads` |
| `GET, POST /api/business/knowledge` | `knowledge` |
| `PUT, DELETE /api/business/knowledge/{id}` | `knowledge` |
| `GET /api/business/conversations` and `/{uuid}` | `conversations` |
| `GET /api/business/analytics` | `reports` |

The key determines the business. A submitted `business_id` cannot switch the tenant. A key’s abilities and current team permissions are both checked.

## Webhook verification

The receiver should verify `X-AI-Lead-Signature` as hexadecimal HMAC-SHA256 of:

```text
X-AI-Lead-Timestamp + "." + exact raw request body
```

Use the endpoint’s signing secret. Reject stale timestamps and deduplicate `X-AI-Lead-Event-Id`. Delivery is retried, so receivers must support repeated events. Redirects are not followed. Webhook URLs are HTTPS and must resolve to globally routable addresses.

## Tests

```sh
php artisan test
vendor/bin/pint --test
npm run build -- --configLoader runner
```

Default tests force an in-memory SQLite database, regardless of the application’s `.env`. MySQL tests use a separate configuration that forces the test database name:

```sh
php artisan test --configuration=phpunit.mysql.xml
```

Supply a MySQL test user granted access only to `ai_lead_agent_test`. In this workspace, `node .tools/test-mysql.mjs` uses the separately generated credentials. Do not grant this test user access to application databases.

Browser acceptance uses an isolated SQLite file and a separate customer website:

```sh
node tests/browser/smoke.mjs
```

Set `PHP_BINARY` and `CHROME_PATH` if the supplied Windows runtime and Chrome paths do not apply. Browser screenshots are written beneath `.tools/browser/`. The test checks registration, training, embedding, CSS isolation, mobile layout, lead capture, CRM updates, human handoff, and appointment confirmation. It uses the truthful low-relevance response path and does not claim a real DeepSeek request passed.

## Containers

The Docker build provides PHP-FPM, Nginx, built assets, MySQL 8.4, a queue worker, and a scheduler. Set a strong `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `APP_KEY`, and the correct public `APP_URL` in `.env`.

```sh
docker compose up -d --build mysql app web
docker compose exec app php artisan migrate --seed --force
docker compose exec app php artisan app:create-admin
docker compose up -d worker scheduler
```

The local web port is 8080. Use an HTTPS reverse proxy for public hosting and configure trusted proxies for that deployment. Docker is not available in the supplied environment, so the container build itself has not been executed here.

## Release status

See [the acceptance record](docs/ACCEPTANCE.md). This is a substantial working implementation, but it has **not been certified production-ready** under the pasted prompt’s final acceptance rule. Live DeepSeek, captured gateway payments, SMTP delivery, deployment, backups, and load behavior require verification with real credentials and infrastructure.

Further specification items include the complete step-by-step onboarding wizard, advanced proactive rules and pageview tracking, file attachments, widget avatars/themes/language controls, advanced visitor/source reports, AI tag suggestions, general condition/action automation, platform maintenance controls, and recurring/overage billing. These are not represented by fake working controls.

Provider adapters for OpenAI/Gemini/Anthropic, embedding search, calendar integrations, advanced WhatsApp, and custom white-label domain provisioning are future extensions. Register additional provider classes in `config/ai.php`. A fallback is invoked only when explicitly configured.

Implementation references: [Laravel release requirements](https://laravel.com/docs/13.x/releases), [DeepSeek chat completions](https://api-docs.deepseek.com/api/create-chat-completion/), and [MySQL Windows archive installation](https://dev.mysql.com/doc/refman/8.4/en/windows-extract-archive.html).

