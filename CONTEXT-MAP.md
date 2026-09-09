# Context Map

## Contexts

- [Email Reading Automation](docs/domain/email-reading-automation/CONTEXT.md) — existing production flow; personalized psychic-reading emails generated live, per customer, per order
- [Campaign Email Automation](docs/domain/campaign-email-automation/CONTEXT.md) — new Klaviyo-campaign-driven flow; pre-generated, non-personalized emails attributed back to a marketing campaign
- [AI Chatbot](docs/domain/ai-chatbot/CONTEXT.md) — storefront chat widget with MCP tool-calling and a retrieval-augmented store-knowledge base

## Relationships

- **Isolated siblings** — no shared write access to any table, no shared controller/job code path between the two contexts. Each owns its own webhook routes, delivery table, and queues. See [ADR 0001](docs/adr/0001-campaign-flow-isolated-from-reading-flow.md).
- **Shared infrastructure only**: `shopify_webhook_events` (generic webhook audit/dedup log, insert-only, not domain state) and `ShippingScheduleResolver` (the shipping-method-based delivery-timing algorithm, one shared implementation with a separate config namespace per context) are used by both contexts.
- The **AI Chatbot** context is independent of both — no shared tables, controllers, or jobs with either email pipeline. It shares the legacy `configurations` table with the rest of the admin panel (Site/Availability/Reading/Shopify settings) but reads it through its own dedicated `Chatbot.*`-prefixed rows and repository, never touching those other settings.
