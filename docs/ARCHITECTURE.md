# Architecture

`TenantContext` is request-scoped. Dashboard middleware resolves a business from the authenticated user’s memberships. Widget middleware establishes the context from the public widget UUID after checking business/account status and the approved Origin. API middleware establishes it from a Sanctum key’s business binding and checks current membership and abilities. Queue jobs carry business and record IDs and explicitly enter and restore the context.

`TenantModel` applies a global business scope and fills the business ID on insert. A missing context throws. Updates and deletes check the current context. Unscoped lookups are limited to establishing a widget’s tenant, owned-business administration, key authentication, and super admin reporting. Controllers retrieve records inside the established context; user-supplied business IDs never select a tenant.

Catalog records synchronize into knowledge documents and chunks. Website and upload jobs produce the same searchable records. MySQL uses a full-text index with a keyword fallback; SQLite uses the keyword path. The retrieval strategy can be replaced with vector or hybrid retrieval without changing controllers or provider adapters.

Conversation requests retrieve limited chunks and history. Owner instructions and behavior settings build the system prompt; business knowledge and visitor/page information are treated as untrusted data. Low-relevance business questions use an honest fallback. Static answer caching is restricted to first-turn hours/location questions and hashes the full business context.

Usage reservations serialize on a business row, record calendar-month usage, and reserve a conservative token budget before an external call. Failed provider calls refund that reservation. Successful requests settle actual usage and write token/cost logs. Resource creation uses the same serialization boundary for count-based limits. Lead scoring uses tenant-configured weights, caps at 100, and records the breakdown.

Lead creation and qualification emit dashboard/email alerts and webhook outbox records. Signed delivery jobs use unique event identifiers, bounded retries, globally routable destinations, pinned DNS resolution, and no redirects. Automation rules execute configured tasks, tags, or email actions. The scheduler processes abandoned sessions, summaries, retention, subscriptions, pending webhook delivery, and daily metrics.

Razorpay is behind `PaymentGatewayInterface`. Order amounts and plans are recorded before verification; callback signatures and captured amounts are checked against server records. Verification is idempotent. This implementation buys fixed access periods and does not manage recurring payment mandates.

The widget is a standalone public asset. It uses Shadow DOM, text rendering, a random anonymous visitor identifier, a hashed server-side session token, exact domains, API rate limits, and per-business quotas. Tokens and public configuration never include the AI key, internal prompt, owner instructions, or knowledge citations. Citations are available only in authenticated workspace views and the tester.

Agency owners create separately isolated client businesses. Business creation limits come from active plans. White-label settings are business-scoped and applied only when the business plan permits them. Client plans and usage remain separate; there is no shared agency usage pool.

Production considerations remain: network-level egress controls, application monitoring, backups and restore drills, workload-specific queue sizing, verified payment/email delivery, load testing, and the remaining product scope listed in the README. Container files and CI checks are provided; no public deployment was performed.
