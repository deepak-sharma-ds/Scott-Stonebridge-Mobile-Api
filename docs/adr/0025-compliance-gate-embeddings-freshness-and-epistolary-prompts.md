# Hybrid Compliance Gate, Embedding-Assisted Freshness Protocol, and Epistolary Prompts

Status: accepted

## Context
Automating personalized psychic email readings introduces serious compliance liabilities under UK regulations (Consumer Protection from Unfair Trading Regulations 2008, Cancer Act 1939, and ASA/CAP advertising rules). If customers submit high-liability questions (medical diagnosis, pregnancy complications, cancer/terminal illness, lottery/financial investments, criminal/legal disputes, or self-harm/suicide) and an automated AI model answers carelessly, the business incurs severe legal, financial, and reputational risk.

Additionally, repeat customers ordering the same reading product previously received formulaic, identical card draws and interpretations because generations were isolated with no awareness of past readings. Naively passing complete past readings (1,500+ words) into the prompt would bloat token costs and slow down processing. Furthermore, default formatting rules prompted the model to emit blockquotes (`>`), rendering purple vertical stripes (`border-left: 3px solid #d8cbe8`) and robotic bullet points that immediately signaled "AI-generated" content.

## Decision
1. **Hybrid Compliance Gate with `compliance_hold`:**
   - **Critical Breaches:** Inquiries containing self-harm, suicide, violence, direct criminal accusations, medical diagnoses, prescriptions, or pregnancy medical outcomes trigger an immediate halt prior to generation. The delivery transitions to `compliance_hold`, automated dispatch is stopped, and an alert is queued for human review. Evaluated using a zero-cost pre-screen (OpenAI Moderation API + critical keyword regex) before generating text.
   - **Sensitive Guidance Deflection:** Softer spiritual/emotional topics (general health anxiety, life transitions, relationship heartbreak) are processed normally, but Scott's system prompt strictly instructs the model to provide compassionate spiritual comfort while explicitly advising professional medical, financial, or legal counsel.
2. **Freshness Protocol with Compact Injections and Embeddings:**
   - For returning customers, the generation service looks up past sent readings for that email.
   - To avoid token bloat, only a compact 15-token summary of previous themes and cards is injected into the user turn (`"Previous reading explored: [Cards/Themes]. Provide fresh cards and new perspectives."`).
   - Each completed reading's embedding (`text-embedding-3-small`) is calculated and stored. A semantic cosine similarity check ensures the new response does not repeat past concepts (>0.85 similarity triggers a single retry with increased presence penalty).
   - The stored embedding corpus creates a structured historical dataset for future custom agent development and memory.
3. **Pure Epistolary Formatting Contract (Eliminating AI Visual Tells):**
   - The formatting contract is upgraded to enforce a natural personal letter format in flowing paragraphs.
   - Blockquotes (`>`), markdown tables, bullet points, horizontal dividers (`---`), and decorative symbols are strictly prohibited.
   - The CSS engine (`InlineEmailStyles` and email templates) removes `border-left` on blockquotes to permanently eliminate vertical stripes.
4. **Modality-Specific Product Prompts (Two-Tier Architecture):**
   - Retain the global persona in the system prompt while upgrading product templates in `email_reading_products` with authentic reader mechanics:
     - *Tarot products* explicitly draw and interpret named cards with synthesis.
     - *Mediumship products* channel comforting sensory impressions and messages from loved ones, strictly without Tarot cards.
     - *Love products* focus on energetic relational dynamics and communication.
     - *Astrology products* walk through seasonal quarterly roadmaps.

## Considered Options
- **Hard-reject all sensitive questions automatically:** rejected — rejecting every order mentioning health or stress would reject legitimate customers asking for general emotional comfort; the hybrid model safely routes critical risks to human review while answering benign spiritual inquiries with disclaimers.
- **Injecting full past reading texts into the prompt:** rejected — passing 2,000–4,000 tokens of past reading text per repeat order needlessly inflates OpenAI token bills; the compact 15-token theme tag achieves identical freshness guidance at negligible cost.
- **Relying solely on high temperature without history lookup:** rejected — without knowing past cards or themes, stochastic sampling still periodically repeats the same major arcana cards or metaphors for the same customer.
- **Complex multi-agent review pipeline (Critic/Rewriter):** rejected — making multiple roundtrip LLM completions per reading triples latency and cost; single-call completion with negative constraints and local verification meets all requirements.

## Consequences
- Deliveries with critical compliance issues can never be automatically emailed to customers without human sign-off.
- Repeat customers consistently receive fresh, progressive readings without inflating token consumption.
- Email bodies read like genuine, handwritten personal letters from Scott Stonebridge with no visual AI artifacts.
- The platform accumulates a clean vector dataset of readings for future fine-tuning or AI agent capabilities.
