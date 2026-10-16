# Campaign Email Automation

A second, independent email pipeline: a Klaviyo marketing campaign — built and sent entirely inside Klaviyo — links to unlisted Shopify products. A purchase is attributed back to the campaign it came from so the correct pre-generated response is delivered on a schedule tied to shipping method, then the order is fulfilled. Sibling to, and fully isolated from, [Email Reading Automation](../email-reading-automation/CONTEXT.md) — never shares a controller, job, or delivery table with it.

## Language

**Marketing Campaign** (`marketing_campaigns`):
Laravel's own admin-registered authorization record for a Klaviyo campaign — NOT the Klaviyo campaign itself, and not synced from it. Identified by an admin-chosen `campaign_key` (the durable identifier used everywhere downstream), soft-linked to Klaviyo's own campaign by an informational `klaviyo_campaign_id` string only, never a foreign key. Only a campaign with `status = active` authorizes a purchase attribution.
_Avoid_: Campaign (ambiguous — the existing `KlaviyoCampaignSweep` model already uses "campaign" for the raw Klaviyo object in the unrelated push-notification feature), Klaviyo Campaign (that's the external object; we never store or sync its state, only its id string)

**Campaign Product** (`campaign_products`):
One (Marketing Campaign, Shopify Product) pairing. The same Shopify product can belong to many Campaign Products across different campaigns — this is what resolves a product being reused across campaigns without ambiguity, since attribution always resolves to one specific pairing, never to the bare product.

**Campaign Response** (`campaign_product_responses`):
The email content for one Campaign Product — either OpenAI-generated or admin hand-authored, tagged by a `source` column (`ai`|`manual`). Exactly one per Campaign Product, enforced by a unique constraint; produced once by an admin action before the campaign sends — never regenerated per customer, never generated live at order time. See [ADR 0003](../../adr/0003-campaign-responses-pregenerated-not-live.md) and [ADR 0004](../../adr/0004-campaign-responses-allow-manual-authoring.md).
_Avoid_: AI Response (no longer accurate — content may not be AI at all), Personalized Response (deliberately generic and shared across every buyer of that pairing, unlike Email Reading Automation's Personalized Response)

**Campaign Delivery** (`campaign_deliveries`):
The per-line-item unit of work: one order line item's assignment to a resolved Campaign Response, from purchase through sent email and Shopify fulfillment. Uniquely keyed by `shopify_line_item_id`. One Campaign Delivery per line item; `scheduled_at` is computed once per order and shared across every Campaign Delivery in that order.
_Avoid_: Order, Email, Send

**Campaign Key**:
The admin-chosen slug a purchase carries — via a hidden Shopify line-item property, `_campaign_key` — to identify which Marketing Campaign it came from. The sole attribution mechanism. See [ADR 0002](../../adr/0002-campaign-attribution-via-line-item-property-fail-safe.md).

**Attribution Failure**:
The terminal state when a Campaign Delivery cannot be resolved to exactly one active Campaign Response — missing or unknown campaign key, an inactive campaign, or no Campaign Response generated yet. Distinct from a transient failure: never *automatically* retried, always admin-notified, order never auto-fulfilled. An admin may manually re-pair and resend once the underlying cause is fixed — see [ADR 0005](../../adr/0005-manual-admin-repair-of-attribution-failures.md).

**Vip Product** (`unlisted_products.is_vip`):
A Shopify product carrying the `vip` tag. The tag is read during the Unlisted Products sync and snapshotted locally, because webhook payloads carry no product tags; the flag follows the tag on every sync. Only Vip Products can be linked to a Marketing Campaign, and the campaign webhook only attributes Vip Products. A Vip Product that is also an automation-enabled Reading Product is an ambiguous registration and is refused by both flows.

**Unlisted Products sync**:
Refreshes only Shopify-owned fields (title, image, variants, vip flag, sync stamp) of products already in the catalog; never touches `is_published` or admin-authored email fields, and never unpublishes or deletes anything. A product seen for the first time is created published.

**Dispatcher / Claim / Sending / Due Date / Reactivate / Mark as sent manually / Unfulfilled**:
Same meanings as in [Email Reading Automation](../email-reading-automation/CONTEXT.md), mirrored here (the flows share no code). A Campaign Delivery is eligible to send while `pending` and past its Due Date; it has no generation step. Reactivating requires a resolved Campaign Response. Unsent Campaign Deliveries send the *live* Campaign Response text, so editing a response reaches every unsent buyer (the admin sees how many before saving).

**Formatting Contract**:
The shared structural and stylistic rules instructing how a generated campaign response must be composed: designated headings per theme or section, key-message lines, a closing section, British spelling, and omitting greetings and sign-offs already provided by the campaign template. Configurable globally or overridden per campaign product.

**Generation Validation**:
The quality check performed on AI-generated campaign responses prior to storage. If truncation, leftover markdown tokens, or missing required sections are detected, the response is not automatically saved; instead, the administrator is presented with a warning detailing the detected problems, and acceptance is held until formatting checks pass (by editing in the editor and re-checking) or generation is re-run.

**Email Preview**:
The isolated, full-fidelity administrative view showing precisely how the campaign email will render for a recipient — including campaign header, banner, formatted body, and footer — rendered in responsive desktop and mobile viewports with an optional zero-side-effect test send to the viewing administrator. Previews and test sends are never tracked: no Delivery Proof headers and no tracking pixel.

**Delivery Proof**:
The verifiable, server-to-server confirmation captured upon mail dispatch: the unique RFC message identifier and the recipient mail exchange server's acceptance handshake code, establishing that the email was successfully accepted by the recipient's mail provider, plus the moment of acceptance (`dispatched_at`). Serves as primary technical fulfillment evidence in payment disputes and regulatory compliance audits.
_Avoid_: Sent Status, Mail Log

**Open Tracking**:
The measurement of client-side recipient engagement via a unique tracking token and transparent pixel embedded in the email, recording the initial open timestamp, subsequent interaction count, and client metadata. Serves as operational customer-engagement visibility.
_Avoid_: Read Receipt, Spy Pixel

**Tracking Token**:
The permanent UUID on a delivery that identifies its open-tracking pixel. It never changes, so opening any copy of the email, including a resend, records engagement against the same delivery.
_Avoid_: Open ID, Pixel ID

**Direct Open**:
An open event initiated directly by a human recipient's mail application, indicating authentic, unproxied client interaction.
_Avoid_: Real Open

**Proxy Open**:
An open event initiated by an intermediary privacy proxy or automated security cache (such as Apple Mail Privacy Protection or Google Image Proxy), indicating that email assets were loaded via an external proxy network upon arrival.
_Avoid_: Fake Open, Bot Open

**Send History**:
The chronological, immutable audit log of all mail transmissions associated with a single delivery. Each record captures the dispatch timestamp, RFC message identifier, and SMTP server handshake response, preserving proof of timely fulfillment even after administrative resends.
_Avoid_: Resend Log, Retry Counter



