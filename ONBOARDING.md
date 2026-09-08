# Onboarding Map — Scott-Stonebridge-Mobile-Api

This is a mental-model walkthrough of the codebase, not a bug list. It's meant to get a new engineer (human or agent) oriented quickly: what this app actually is, how the modules fit together, what the database looks like, and where the documentation lives (including docs that are stale but still worth reading for context).

## What this app is

One Laravel monolith wearing several hats at once:

1. **Shopify storefront/mobile-app backend** — products, cart, orders, profile, wishlist, content, for a mobile app and/or headless storefront.
2. **A psychic-reading business's booking + paid audio-content platform** — meeting scheduling (Google Calendar-backed), HLS-streamed audio subscriptions gated by Shopify purchase tags.
3. **Two parallel "AI-generates-content-then-emails-it" marketing pipelines** — personalized reading deliveries, and Klaviyo-campaign-attributed product blurbs.
4. **Push notification / Klaviyo bridge** — Firebase push, driven by Klaviyo webhooks and a polling "sweep."
5. **An AI shopping assistant / chatbot** — the most actively developed area right now (see `docs/domain/ai-chatbot/CONTEXT.md` and `docs/adr/0006`–`0010` — already covered in depth elsewhere; this doc treats it as "known, detailed separately").
6. **An admin back office** tying all of the above together (Blade admin panel, session auth, Spatie roles/permissions).

It reads as a codebase that's grown in at least two distinct eras: an older "booking/audio/legacy-Shopify" era, and a newer, more disciplined era (typed DTOs, `BaseService`/`BaseApiController`, ADRs, domain-modeling docs) layered on top starting mid-2026. Knowing which era a file belongs to explains a lot of the duplication described below.

## Routing map

- `routes/web.php` — just requires the others.
- `routes/api.php` — requires `shopify_website.php`, `mobile.php`, `api_v1.php`, `api_v2.php`.
- `routes/api_v1.php` — the current, versioned customer-facing API (also where all `ai/*` chatbot/sales-agent routes live).
- `routes/api_v2.php` — empty placeholder, everything 501s. Scaffolding, not real yet.
- `routes/mobile.php` / `routes/shopify_website.php` — legacy unversioned customer-facing API, explicitly commented "maintained for backward compatibility."
- `routes/admin.php` — the Blade admin panel.
- `routes/auth.php` — stock Breeze-style session auth for the admin.
- `routes/webhook.php` — all inbound Shopify/Klaviyo webhooks.
- `routes/developer.php` — dev/ops grab-bag (see caveats below).

## Major modules

### 1. Shopify Storefront / Mobile API — two parallel generations

- **Current** (`Api/V1/*` controllers → `Services/Shopify/*` → `Http/Resources/*`, all extending `BaseApiController`/`BaseService` for a standardized JSON envelope + correlation IDs + structured logging). Auth via `shopify.auth` middleware (`ShopifyAuthMiddleware`), which verifies a bearer token against the Storefront GraphQL API per request.
- **Legacy** (`Apis/*` controllers, unversioned routes, explicitly marked backward-compat). Different auth guard (`shopify.customer.auth` → `ShopifyCustomerAuthService`/`APIShopifyService` — an older, separate Shopify integration that predates the `Clients/Shopify` rewrite). The top-level `App\Services\ShopifyService` (singular) is the old catch-all still used by `ShopifyController`.

**New work belongs in V1 / `Services/Shopify`.** The `Apis/*` + `ShopifyService`/`APIShopifyService` pairing is the clearest "two implementations of the same integration" in the repo — useful to recognize so you don't accidentally extend the legacy path.

### 2. Shared Shopify client infrastructure (cross-cutting core)

`Clients/Shopify/{Admin,Storefront}ApiClient` extend `Clients/Base/BaseShopifyClient` (retries, circuit breaker, cached-with-fallback responses, correlation-id logging). `Services/Shopify/ShopifyManager` (bound to a `Shopify` facade) exposes `->storefront()`/`->admin()`/`->query($type, $path, $vars)`, loading GraphQL documents from `app/GraphQL/**` by path via `GraphQLLoaderService`. Almost every domain routes Shopify calls through this. `Services/Base/BaseService` is the equivalent base class most newer services extend for correlation-id plumbing and structured logging — the older services (`ShopifyService`, `BookingService`, `AvailabilityGenerator`, the `Configuration` model) don't, and just call `Log::channel()` directly.

### 3. Booking / Availability

A Calendly-style scheduling feature backed by Google Calendar. `AvailabilityTemplate` (weekly recurring pattern) → `AvailabilityGenerator` materializes it into `AvailabilityDate`/`TimeSlot` rows (skipping UK bank holidays fetched live from gov.uk), consumed by `ScheduledMeeting`. `GoogleToken` holds OAuth credentials in the DB. Admin CRUD lives under `Admin\Availability*Controller`; the public booking form is the root-namespace `BookingController` + `BookingService` (Google Calendar event create/delete). One of the least-abstracted, most hand-rolled areas of the code — worth expecting inline try/catch over service-layer patterns here.

### 4. Audio content / subscriptions

`Package` (tied to a `shopify_tag`) → `Audio` (HLS-encoded tracks) → `CustomerEntitlement` (which Shopify customers/emails can access which package). `AudioService` + `CustomerEntitlementService` (looks up/tags Shopify customers via Admin GraphQL). `ConvertAudioToHls` job handles encoding. Playback: `PlaySession` (short-lived signed streaming token) vs `PlaybackSession` (resume-position tracker) — similarly named, different purposes, worth not conflating. `HlsController` serves signed `.m3u8`/segments; `AudioAccessController` grants access (has a leftover `/test/test/test/test` debug route still registered).

### 5. Campaign Email Automation

Marketing-campaign-attributed product emails. `MarketingCampaign` → `CampaignProduct` → `CampaignProductResponse` (one pre-generated AI-or-manual response per product, never per delivery — see ADR 0003/0004/0005) → `CampaignDelivery` (per-order-line, status machine pending→generated→sent→failed/cancelled). Triggered by HMAC-verified webhooks (`Webhook\Campaign\*`) dispatching `Jobs\Campaign\*`. Fully isolated from Email Reading Automation by design (ADR 0001) — no shared tables/controllers/jobs beyond generic plumbing.

### 6. Email Reading Automation

The original production pipeline: a customer buys a personalized "reading," submits question/answers as Shopify line-item properties, and `EmailReadingGenerationService` (calls OpenAI directly, Blade-renders a per-product prompt template) generates and emails a response on a schedule tied to shipping method. Mirrors Campaign Email's shape deliberately (ADR 0001) but stays a separate, isolated sibling. A separate, unrelated lead-capture form (`FreeReadingController`/`FreeReadingSubmission`) also lives here — simple email capture, no AI generation, no relation to `ai_leads`.

### 7. Push Notifications & Klaviyo

`DeviceToken` (FCM tokens) + `PushNotification` (sent/queued record, polymorphic-ish `source_type`/`source_id`). Two Klaviyo integration paths: (a) `Webhook\KlaviyoFlowWebhookController` — a webhook a marketer wires inside a Klaviyo flow, secret-verified, dedup'd; (b) `push:sweep-klaviyo-campaigns` command polling Klaviyo's Campaigns API (no native "sent" webhook exists) via `KlaviyoCampaignSweep` bookkeeping. `Clients\Klaviyo\KlaviyoApiClient` is a well-commented JSON:API client with useful notes on Klaviyo quirks. Delivery itself goes through Firebase (`kreait/firebase`) via `Services\Push\PushNotificationService`.

### 8. AI Sales Agent (adjacent to, distinct from, the chat widget itself)

Proactive on-site triggers (`TriggerRule`/`ProactiveTriggerService`), lead capture (`AiLead`/`LeadCaptureService`), upsell suggestions (`UpsellService`), the merchant knowledge base (`StoreKnowledge`/`StoreKnowledgeService`/`KnowledgeChunker` — chunking per ADR 0009), and conversion analytics (`ConversionEvent`). Fed by `knowledge:sync`/`knowledge:sync-products`/`knowledge:sync-urls`/`knowledge:embed` commands. This shares infrastructure with the chatbot proper (`docs/ai-chatbot/*`, `docs/domain/ai-chatbot/CONTEXT.md`) — treat as already covered elsewhere.

### 9. Admin Configuration Panel & access control

`Configuration` model — a key/value settings table, dot-namespaced (`prefix.key`), CRUD via `Admin\ConfigurationsController`. This is the same legacy table the chatbot's `Chatbot.*` config rows now live in (see ADR 0006 — the chatbot migration deliberately left this model/controller untouched). Old-style code (no `BaseService`, custom accessor methods). Access control is Spatie `laravel-permission` (`Role`/`permissions` tables) + stock Breeze session auth, entirely separate from the Shopify customer-auth guards used by the API.

### 10. Admin dashboard, analytics, reporting

`Admin\DashboardController` (landing page), `Admin\AnalyticsController`/`ReportingController` (dashboard metrics, CSV export) backed by `AnalyticsService`. Note there are **three** same-named "AnalyticsService" concepts in different namespaces (`Services\AnalyticsService`, `Services\Shopify\ShopifyAnalyticsService`, `Services\AI\AnalyticsService`) — easy to grab the wrong one.

### 11. Contact / Content / CMS

Public content endpoints (`Api\V1\ContentController` — pages/policies/blogs/media/deep-link resolve) and a contact form (`Api\V1\ContactController`), with legacy equivalents in `Apis\*` per the split in module 1.

## Webhooks (`routes/webhook.php`)

One place to see how a Shopify order fans out into the two "generate + email" pipelines: HMAC-verified pairs for Email Reading orders (`order-paid-reading`/`order-updated-reading`/`order-cancelled-reading`) and Campaign orders (equivalent trio), plus the Klaviyo flow webhook. There's also an older, unrelated appointment-booking + `order-paid` webhook on `ShopifyController` with its HMAC check commented out in code.

## Middleware inventory

`CorrelationIdMiddleware`, `ApiLoggingMiddleware`, `RateLimitMiddleware`, `CustomCors`, `DisableSessionMiddleware` (legacy API group), `LogUserActivity`, `ResponseCacheMiddleware`. Watch for near-duplicate pairs that aren't actually redundant so much as historically accumulated: `CurrencyMiddleware` vs `SetCurrency`, `ShopifyAuthMiddleware` vs `ShopifyCustomerAuth` (module 1's two guards), `VerifyShopifyHmac` vs `VerifyShopifyWebhookHmac` — worth checking which is actually wired before assuming.

## Database schema

The schema mirrors the same two eras as the code. Grouped by domain (not chronological):

**Framework/auth**: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, `personal_access_tokens` (Sanctum), Spatie permission tables (`permissions`, `roles`, `model_has_*`, `role_has_permissions`).

**Booking**: `availability_dates`, `time_slots`, `availability_templates`, `scheduled_meetings` (later gained `availability_date_id`/`time_slot_id`/`order_id`), `google_tokens`.

**Audio/membership**: `packages` (→ `shopify_tag`), `audio` (gained `hls_path`/`is_hls_ready` after initial launch), `customer_entitlements` (gained `is_download_allowed`; `email` column type widened later — a real prod data-type fix), `play_sessions` (signed streaming tokens), `playback_sessions` (resume position — don't confuse with the previous one).

**Analytics/logging**: `api_logs`, `audio_download_logs`, `analytics_snapshots`.

**Lead capture (two unrelated systems)**: `contact_us_messages`, `free_reading_submissions` (simple form) vs `ai_leads` (chatbot-driven) — no shared table.

**AI Chatbot** (most actively evolving; already covered in depth elsewhere, summarized here for completeness): `ai_conversations` (gained `revenue_attributed`/`conversion_type`/`lead_captured` for funnel attribution — "Phase E"), `ai_messages`, `ai_customer_sessions` (OAuth token per session), `trigger_rules`, `ai_leads` (deliberately a loose string `session_id`, not an FK — survives session deletion by design), `store_knowledge` (clearest schema-evolution trail in the DB: summary-only → FULLTEXT → OpenAI embeddings → document chunking, across ~4 migrations), `conversion_events` (append-only, no `updated_at`), `shop_settings` (gained widget-branding columns later).

**Email Reading**: `email_reading_products`, `email_reading_deliveries` (several ALTERs hardening its lifecycle — nullable Shopify IDs, `scheduled_at`/`expedited_at`/`fulfilled_at`), plus the shared `shopify_webhook_events` audit log.

**Push/Klaviyo**: `device_tokens`, `push_notifications` (gained `read_at`/`cleared_at` for an in-app inbox), `klaviyo_webhook_events`, `klaviyo_campaign_sweeps` (content payload grew from cursor-only to carrying full message bodies across 3 ALTERs).

**Campaign Email**: `marketing_campaigns`, `campaign_products` (gained `shopify_variant_id`), `campaign_product_responses` (one per product, unique constraint), `campaign_deliveries` (structurally near-identical to `email_reading_deliveries` — same status/attempts/error_message shape, different domain).

**Known legacy table with no CREATE migration**: `configurations` — the `Configuration` model's table has no `create_configurations_table.php` anywhere; it predates the current migration history. Already documented in ADR 0006 and tolerated explicitly in `AppServiceProvider::configHandler()` and the chatbot's own seed migration (both check `Schema::hasTable()` first). No other model/table pair in the repo has this gap.

**Deliberate design pattern to know**: several AI-domain tables (`ai_leads.session_id`, `conversion_events.session_id`/`shop_domain`) use plain indexed strings instead of foreign keys, on purpose, so analytics/recovery data survives a parent row's deletion — not an oversight if you see it.

## Documentation map

Docs quality is uneven — some are living/maintained, most are point-in-time engineering notes. Root `CLAUDE.md` says this outright: "Some docs may be outdated... do not treat them as exact implementation blueprints."

**Living / maintained (start here):**
- `CONTEXT-MAP.md` (root) — the three domain contexts and how they relate.
- `docs/domain/*/CONTEXT.md` — glossary + vocabulary for Email Reading Automation, Campaign Email Automation, AI Chatbot. Read the top summary of each; skip to specific terms as needed.
- `docs/adr/0001`–`0010` — architectural decisions, each a short "what/why." Read these before changing anything in an area they cover — they exist specifically so you don't re-litigate or accidentally reverse a deliberate choice.
- `docs/agents/{domain,issue-tracker,triage-labels}.md` — how agent workflows in this repo are meant to operate (issue tracking is local markdown under `.scratch/`, not GitHub Issues, because `gh` isn't reliably available in every environment this repo is worked in).
- `docs/ai-chatbot/*` (except the `frontend-fixes-*` files, which are historical snapshots) — the chatbot's own architecture/config/API-reference docs, already kept in sync with this session's changes.

**Point-in-time / historical (useful for "why does this exist" archaeology, not current-state truth):**
- A large uncategorized pile of ~55 loose files directly under `docs/` — refactor reports, currency/pagination/theme-template-API iteration trails (the Theme Template API cluster alone has ~14 files, several marked "-COMPLETE"/"-FINAL" superseding earlier ones — read the newest-dated one, not all of them), Postman collection notes, GraphQL audits. Mostly undated and read as accreted session notes.
- `docs/development plans/` — original production plans for Email Reading and Campaign Email Automation (still cross-linked from their ADRs, so more reliable than the loose pile above), plus an unrelated personal career-roadmap doc that doesn't belong to the project.
- `docs/chatbot/roadmap/` and root-level `AI-Assistant-Architecture-Overview.md`/`Global-AI-Chatbot-*.md` — at least two more, earlier/parallel planning passes for the chatbot feature, distinct from `docs/ai-chatbot/*` and from what's actually documented in the ADRs. Treat as historical proposals, not as the current contract.
- `Campaign-Klaviyo-Integration-Enhancement-Research.md` (2026-07-21, most recent standalone doc) — primary-source research on Klaviyo API options for a not-yet-built enhancement; nothing in it is implemented yet (confirmed by checking `KlaviyoApiClient`, which is read-only and built for the unrelated push-notification sweep).

## A few things worth knowing before you touch something

- If you're about to extend Shopify integration on the customer-facing side, make sure you're in `Api/V1` + `Services/Shopify`, not `Apis/*` + `ShopifyService`/`APIShopifyService`.
- `routes/developer.php` has some unauthenticated dev/ops routes (cache-clear endpoints, a public `phpinfo()`) that look like leftover scaffolding — worth a conscious decision before relying on or removing them, not something to assume is intentional.
- `Admin\AnalyticsController`'s actions are entirely commented out in the file even though its routes are still registered — dead code, not a working feature today.
- When in doubt about whether a doc reflects current behavior, trust `docs/domain/*/CONTEXT.md` + `docs/adr/*` + the actual code over anything in the loose top-level `docs/` pile.
