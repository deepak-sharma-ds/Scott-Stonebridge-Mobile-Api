# Email Reading Automation

Existing production flow: a customer buys a personalized psychic-reading product on Shopify; Laravel generates a response unique to that customer's submitted answers and emails it on a schedule tied to shipping method, then fulfills the order.

## Language

**Reading Product** (`email_reading_products`):
A Shopify product registered in this feature's own DB registry. Identified by Shopify's numeric product ID, never by tag (a product that is vip-tagged or also a Campaign Product belongs to the Campaign flow - see the Campaign context's **Vip Product**; if it is also a Reading Product the order line is refused and flagged, never guessed at) — tags are mutable from Shopify admin and not reliably present on webhook line items. A row can exist before it has a question schema or prompt template — see Checkout-Registered and Automation Enabled, two independent gates on the same row. Rows are created/refreshed by the Reading Catalog Sync, never by manually typing a Shopify product ID.
_Avoid_: Product (ambiguous with the raw Shopify product record)

**Checkout-Registered**:
Not a stored flag — a computed check the checkout-facing questions API makes per request: the product's `email_reading_products` row exists and `is_active` is true. That's the whole gate. An active product with an empty `questions_schema` is still Checkout-Registered — it's a legitimate, permanent state (some readings need no personalization), and the API returns it normally with an empty `questions` list. Only a missing row or `is_active = false` fail the check, both getting the same generic "not available" response — a paused product and a genuinely unregistered product look identical from checkout's side. Gates only whether the checkout extension shows a question form — unrelated to Automation Enabled.

**Automation Enabled** (`is_automation_enabled` on `email_reading_products`, default `false`):
Whether the post-purchase pipeline auto-generates and sends the AI email response for this product. Checked alongside `is_active` in the webhook's product match (`EmailReadingProduct::active()->where('is_automation_enabled', true)`) — a line item for a product with this false is skipped exactly as if the product were unmatched: no `EmailReadingDelivery` row, no pipeline involvement. When false, Scott handles that product's reading emails manually outside the system, same as before this automation existed — Laravel makes no attempt to verify the manual side is actually happening, and doesn't persist the checkout-submitted answers anywhere for it. Manually toggled by the admin (not inferred from schema completeness), independent of whether the product is Checkout-Registered — a product can capture questions at checkout while still being emailed manually.

**Reading Catalog Sync**:
The admin action that populates/refreshes `email_reading_products` from Shopify: admin picks one or more collections, then picks specific products from those collections in a dependent dropdown (never types a Shopify product ID by hand), and submit re-fetches those product IDs from Shopify's Admin GraphQL API server-side before upserting — so a spoofed/stale browser payload is never trusted. There is no general all-products mirror table; only explicitly picked products get a row.

**Delivery** (`EmailReadingDelivery`):
The per-line-item unit of work: one Shopify order line item's personalized reading, from purchase through sent email and Shopify fulfillment. Uniquely keyed by `shopify_line_item_id`.
_Avoid_: Order, Email, Send

**Personalized Response**:
The OpenAI-generated reading text, unique per customer because it's built from that customer's own submitted question answers, captured as line-item `properties[]` at checkout. Generated live, at order time.
_Avoid_: AI Response (too generic — collides with Campaign Email Automation's differently-scoped Campaign Response)

**Answer Capture Contract**:
The line-item property a question's answer must be submitted under is the schema's `key` verbatim (e.g. `properties[Q1]`) — never the label text. Already true of today's PDP form (confirmed from its rendered markup: `name="properties[Q1]"`), so the checkout extension replicates an existing convention rather than introducing a new one, and `EmailReadingDelivery::normalizeKey()`/`mapProperties()` need no backend changes.

**Webhook Event** (`ShopifyWebhookEvent`):
A raw, audited record of one inbound Shopify webhook delivery, stored *before* it is processed (the Webhook Inbox). Carries a processing status (`received`, `processing`, `processed`, `ignored`, `failed`) and, for `ignored`, the reason nothing happened. Deduplicated per endpoint + Shopify webhook id. Shared infrastructure across contexts - not owned exclusively by this one. See [Context Map](../../../CONTEXT-MAP.md).

**Due Date** (`scheduled_at` on a Delivery):
The moment a generated Delivery becomes eligible to send. It lives only on the Delivery row - no queued job carries it. Editing it (admin reschedule, same-day Expedite) simply changes when the Dispatcher will pick the Delivery up.

**Dispatcher**:
The per-minute scheduled process that finds Deliveries that are `generated` and past their Due Date, atomically Claims each, and queues one immediate send job. The only thing that starts an automated send.

**Claim**:
The atomic `generated -> sending` transition that gives exactly one send job ownership of a Delivery, identified by a claim token. A job whose token no longer matches the Delivery's does nothing, so a stale or duplicate job can never send a second email.

**Sending** (Delivery status):
A claimed Delivery whose email is being sent. Frozen: admin actions (cancel, reschedule, edit, delete, send now, resend, mark sent) are rejected until the send finishes or is recovered. A Delivery stuck in Sending is released once back to `generated`; stalling a second time marks it `failed` and notifies an admin.

**Reactivate**:
The admin action that returns a `cancelled` or `failed` Delivery to the pipeline: to `generated` if its Personalized Response exists (the Dispatcher sends it when due), otherwise to `pending` with generation re-queued.

**Mark as sent manually**:
The admin action that closes a Delivery Scott already emailed by hand. No email is sent; the Delivery becomes `sent` and is flagged `sent_manually`. Shopify fulfilment is queued by default, with an opt-out.

**Unfulfilled**:
A `sent` Delivery whose Shopify fulfilment did not complete (`fulfilled_at` empty, usually with a stored `fulfillment_error`). Shown as a badge and retryable; it never changes the Delivery's email status.

**Formatting Contract**:
The shared structural and stylistic rules instructing how a generated response must be composed: designated headings per section or question, short key-message focal lines, a dedicated closing section, British spelling conventions, and strictly omitting redundant customer greetings or sign-offs that the email template already provides. Can be customized per product.

**Generation Validation**:
The automated quality check performed on freshly generated response content prior to acceptance or dispatch. Inspects the output for mid-sentence truncation, leftover markdown formatting tokens, a missing closing section, or redundant greetings and sign-offs. If a flaw is detected, triggers a single automated recovery attempt with expanded token limits; if still flawed, marks the delivery failed and alerts an administrator.

**Email Preview**:
The isolated, full-fidelity administrative view showing precisely how the final email will render for the customer — including brand headers, banners, formatted body styling, and closing sign-offs — rendered in responsive desktop and mobile viewports with an optional zero-side-effect test send to the viewing administrator.

**Compliance Gate**:
The pre- and post-generation evaluation ensuring customer questions and generated reading guidance adhere to legal, safety, and industry boundaries. Inquiries are classified into standard spiritual inquiries, sensitive topics requiring gentle deflection with professional disclaimers (general emotional wellbeing, personal dilemmas), and critical compliance breaches (medical diagnoses/prescriptions, pregnancy health outcomes, self-harm, suicide, criminal accusations, and gambling/investment picks).
_Avoid_: Moderation Check (too technical), Content Filter

**Compliance Hold**:
The delivery state when an inquiry contains a critical compliance breach. Automated generation and dispatch are immediately halted, the delivery is quarantined from the automated pipeline, and an alert is dispatched for human review.
_Avoid_: Banned, Blocked

**Freshness Protocol**:
The validation comparing a freshly generated response against a returning customer's past readings to ensure that repeat buyers receive distinct spiritual perspectives, distinct card draws, and progressive guidance rather than recycled phrasing or identical interpretations.
_Avoid_: Deduplication (too generic)

**Reading Embedding**:
The mathematical semantic representation of a reading's core concepts and themes, preserved alongside the delivery record to power semantic similarity comparison across repeat customer orders and build a structured historical corpus for future AI assistance.
_Avoid_: Vector Array, Float List

