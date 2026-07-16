# From Laravel Backend Engineer to Senior Agentic AI Engineer
### A research-grounded, video-first roadmap (2026)

**Built for:** 3+ years backend engineer (PHP/Laravel/MySQL/MongoDB/REST/GraphQL) → Production Agentic/Generative AI Engineer
**Pace:** 5–10 hrs/week (honest timeline: ~18–22 months to senior-ready, not 12 — see note below)
**Target:** Global remote-first market (US/EU/UAE/Singapore/Canada all viable from India)

---

## 0. Reality Check First — Read This Before Anything Else

A blunt note on pace: most "12-month roadmaps" assume 20–30 hrs/week. At 5–10 hrs/week (the honest number for someone holding a full-time job), the same curriculum realistically takes **70–90 weeks**, not 52. I've restructured this as **6 phases with target durations**, not rigid months, so you don't feel behind when life gets in the way. If you ever bump up to 15–20 hrs/week for a sprint (e.g., a 2-week vacation), you'll compress phases — the plan flexes either way.

**Your unfair advantage:** You are NOT starting from zero. 3 years of backend architecture, auth, payment integration, REST/GraphQL APIs, and deployment experience maps directly onto what agentic AI engineering actually is in 2026: an agentic AI engineer builds something that holds state across tool calls, retries with different strategies, enforces budget caps, and keeps going until a goal is met or a guardrail trips — that loop is the whole job, and none of it is prompt engineering. That's backend systems thinking with an LLM as one more unreliable downstream dependency you have to handle gracefully. Senior backend engineers with 8+ years are explicitly called out as a "natural fit" on-ramp into AI Agent Architect roles — you're earlier than that, but the on-ramp direction is correct.

**What's actually changed in the market (so you don't waste time):**
- The bulk of manual coding is increasingly done by AI agents themselves; engineers are bifurcating into "orchestrators" who define problems, architect systems, and audit output, versus "prompt jockeys" who just feed agents and merge PRs. Your roadmap target is orchestrator, not prompt jockey.
- The 2025 "AI-ready" resume (LangChain demo + Pinecone + ChatGPT wrapper) is now table stakes at best, a red flag at worst. What differentiates in 2026 is agent orchestration, MCP integration, evaluation design, production observability, and cost optimization.
- Software-engineer-enhanced-by-AI (using Cursor/Copilot) is table stakes, not a differentiator. Software-engineer-building-AI-products (RAG pipelines, agent architectures, production integration) is the role companies can't fill fast enough — that's your target.

---

## 1. Research Findings Summary

### 1.1 Hiring trends
- Agentic AI job postings grew 280% YoY, reaching ~90,000 US listings; forward-deployed engineer demand is up over 800% since 2025. Korn Ferry's 2026 survey of 1,674 global talent leaders found 52% plan to deploy autonomous agents by end of 2026, and among companies that already have, 88% are increasing budget.
- Anthropic is scaling its applied AI team 5x in 2026 specifically to embed engineers with enterprise customers running custom Claude implementations — exactly the "forward-deployed engineer" archetype that rewards your backend+API integration background.
- UAE (especially Dubai) has become a major hiring center, with roughly 48% more AI/ML positions being created as government and private capital both pour into automation.
- ~60% of new enterprise software projects in 2026 include an agentic component, and McKinsey notes the largest *new* employment category is actually non-engineering — AI-augmented frontline/ops roles — but the technical roles (Agentic AI Engineer, AI Agent Architect) anchor that ecosystem and pay the most.

### 1.2 What companies actually screen for (from real job-description analysis)
LangChain/LangGraph, CrewAI, tool calling, RAG, MLOps, evaluation/evals, function orchestration, and vector databases dominate listings. Across 534 real agentic-engineering job listings: Python appears in 93.4%, Kubernetes in 27.2% (these are production infra roles, not prototypes), TypeScript in 17.4%, and MCP in 16.9% — the fastest-rising stack item, with Salesforce putting enterprise MCP adoption at 39%.

### 1.3 AI Engineer vs Generative AI Engineer vs Agentic AI Engineer
| Role | Core focus | Day-to-day | Realistic on-ramp |
|---|---|---|---|
| **Generative AI Engineer** | Building applications that create content using foundation models — LLM integration, prompt engineering, fine-tuning, RAG | API wrapping, chat UX, content pipelines | Most accessible entry point for backend/API devs |
| **AI Engineer** (umbrella term) | Builds and runs AI products, agents, and LLMs in production; often relabeled Applied AI / GenAI / LLM Engineer | RAG + light agents + deployment | Same on-ramp as above, broader scope |
| **Agentic AI Engineer** | Builds the actual agent loops — tool calling, sub-agent orchestration, memory, evaluation harnesses; day-to-day looks like backend engineering with heavy focus on prompt design, eval pipelines, and observability | State machines, retries, guardrails, multi-step workflows | **This is your target — closest match to existing backend skills** |
| **AI Agent Architect** (staff+) | Designs the system shape: tool vs sub-agent vs hardcoded path, where humans review, where the agent escalates; less code, more whiteboarding and trade-off analysis | Distributed-systems intuition, cost modeling, security design | 3–5 years out for you; the eventual ceiling |

### 1.4 Global salary benchmarks (2026, total comp unless noted — verify against Levels.fyi before negotiating, these shift fast)
| Region | Entry | Mid | Senior |
|---|---|---|---|
| **US** | $150K–$220K | $230K–$380K | $340K–$550K (staff: $500K-$800K) |
| **India (domestic)** | ₹12L–₹18L | ₹25L–₹45L | ₹55L–₹1.1Cr at top product/AI startups |
| **India (remote-for-US/EU)** | — | $140K–$180K, often with major net-savings advantage due to PPP-based pay + Section 44ADA tax treatment for contractors | $180K–$280K |
| **UAE** | AED 180,000–220,000 | AED 250,000-320,000 | AED 344,959 avg, up to AED 421,195 |
| **Singapore** | SGD 70,000 | SGD 100,000–140,000 | SGD 130,000–220,000+ |
| **UK** | — | — | £90K–£150K base, £110K–£200K total |
| **Germany** | — | — | €85K–€140K base, €100K–€170K total |
| **Canada** | — | — | ~CAD 101,382 average (broader market, not senior-specific) |
| **Agentic-specialist (US, any region remote)** | — | $155K–$265K base | up to $400K total comp for top performers |

**Key insight for you:** India-based engineers working remotely for US/EU firms now routinely out-earn local-market India salaries by 2-3x, and the "global levelling" effect means location is no longer your ceiling — your portfolio is. Build for the global remote market from day one.

### 1.5 What's becoming obsolete vs. emerging (don't waste time)
**Fading fast:** Generic "I called the ChatGPT API" demos, basic prompt engineering as a standalone skill (models increasingly self-correct bad prompts — though structured prompting for agents/evals still matters, it's not a job title anymore), notebook-only ML work with no deployment story, traditional ML certs if you're not targeting research/ML-engineer-training roles.

**Rising fast:** Agent orchestration, MCP server design, agentic RAG (iterative search-evaluate-search-again), production observability for non-deterministic systems, cost-per-interaction monitoring, guardrail/safety engineering. Multi-modal AI, edge AI deployment, AI security (prompt injection defense), synthetic data generation. AI security and governance are becoming table stakes as regulated industries adopt agentic systems — Gartner named AI security platforms a top 2026 investment area.

---

## 2. Must Learn / Good to Learn / Can Skip

### ✅ MUST LEARN (high ROI, directly hireable)
Python (intermediate→advanced, async), FastAPI, Docker, Git/GitHub (you have this), SQL + pgvector, REST/GraphQL for AI (you have this), LLM fundamentals (transformers conceptually, tokenization, embeddings, context windows), prompt engineering for agents (structured outputs, function calling — not generic prompting), RAG (chunking, hybrid search, re-ranking, evaluation), one vector DB deeply (Pinecone or Qdrant or pgvector), LangGraph (production orchestration standard), MCP (becoming infrastructure-mandatory), at least one of CrewAI/AutoGen for multi-agent patterns, OpenAI SDK + Anthropic SDK, evaluation frameworks (RAGAS, LangSmith or Phoenix), basic Kubernetes (27% of jobs require it), observability/tracing for agents, prompt injection / LLM security basics.

### 🟡 GOOD TO LEARN (differentiator, do after Must-Learn)
TypeScript (for LangChain.js / full-stack AI apps), LlamaIndex, DSPy, A2A protocol, voice agents (ElevenLabs), browser/computer-use agents, Kafka/Celery for async agent pipelines, Redis for agent memory/caching, fine-tuning basics (LoRA/QLoRA — useful but not core to your "I build products, not models" goal), one cloud platform deeply (AWS or GCP — pick based on target employer), Terraform/IaC, LangSmith/AgentOps for cost monitoring.

### ⛔ CAN SKIP FOR NOW
Deep linear algebra/calculus proofs, training transformers from scratch, PhD-level ML theory, Hadoop/Spark (unless you pivot to data engineering), most "AI for Everyone"-style non-technical certs (you're past that level), more than one vector DB at the start, more than two agent frameworks at the start (frameworks converge in capability — depth in one beats shallow knowledge of five), Semantic Kernel (smaller adoption vs LangGraph/CrewAI for your target companies), niche state-specific certifications with <1,000 LinkedIn holders (per the certification-credibility filter below).

---

## 3. Framework & Tool Decision Guide (learn the *right* one first)

| Choice | Pick first if... | Why |
|---|---|---|
| **LangGraph vs CrewAI** | Learn **CrewAI first** for speed/intuition, **LangGraph second** for production control | CrewAI gets multi-agent workflows running in ~20 lines vs LangGraph's 60+; they're complementary — CrewAI can use LangChain tools, and the realistic path is "start with CrewAI, migrate the parts that need control to LangGraph," which isn't a rewrite since CrewAI is LangChain-compatible. |
| **MCP vs A2A** | Learn **MCP first**, always | MCP is the practical standard for 80%+ of agent use cases (tool access); A2A matters once you're coordinating independently-built agents across teams/companies — a smaller, later need. MCP also has more mature security practices today. |
| **Pinecone vs pgvector vs Qdrant** | **pgvector** if you want to leverage your existing PostgreSQL/MySQL backend instincts; **Pinecone** if targeting startups that want zero-ops managed search | Both appear heavily in job listings; pgvector is the lowest-friction entry for a backend engineer because you already think in terms of relational data models. |
| **OpenAI SDK vs Anthropic SDK** | Learn **both**, but go deep on Anthropic SDK + MCP first | Anthropic invented MCP and is scaling applied engineering roles aggressively in 2026; the SDKs are similar enough that depth in one transfers fast. |
| **AWS vs GCP vs Azure** | **AWS** if budget/cert-ROI is the deciding factor (most enterprise GenAI job postings reference Bedrock/SageMaker); **GCP** if targeting Google-ecosystem or research-adjacent teams | MLOps, RAG, and agentic frameworks like LangChain/PyTorch are baseline expectations regardless of cloud — pick one and go deep rather than spreading across three. |

---

## 4. The Roadmap — 6 Phases

> Each phase lists: Objectives · Core skills · Why it matters · Est. time at 5–10 hrs/wk · Hands-on project · Portfolio milestone. Resources are grouped separately in Section 5 so this stays scannable.

### Phase 1 — Python for Production AI (6–8 weeks)
**Objectives:** Get fluent enough in Python that it feels like a second native language (the way PHP/Laravel does now).
**Skills:** Core Python, type hints, async/await, virtual environments, pytest, FastAPI basics, Pydantic, Docker fundamentals.
**Why it matters:** 93.4% of agentic engineering jobs require Python — it is the non-negotiable foundation. FastAPI specifically because it mirrors the REST/GraphQL backend patterns you already know — fastest possible transfer of existing skill.
**Project:** Rebuild one of your existing Laravel REST APIs (e.g., your payment-gateway integration logic) in FastAPI + Pydantic + Docker, deployed on Railway or Fly.io.
**Portfolio milestone:** A dockerized FastAPI service on GitHub with tests, OpenAPI docs, and a one-paragraph README explaining the architecture decision.

### Phase 2 — LLM & GenAI Foundations (6–8 weeks)
**Objectives:** Understand transformers/attention/tokenization conceptually (not mathematically from scratch), and become fluent calling LLM APIs correctly.
**Skills:** Tokenization, embeddings, context windows, function/tool calling, structured outputs (JSON mode/Pydantic schemas), system prompts, OpenAI SDK, Anthropic SDK, basic reasoning-model behavior (o-series, Claude extended thinking), multimodal basics (vision input).
**Why it matters:** This is the conceptual floor everything else builds on. You do NOT need to derive backprop — if you're a software engineer who wants to build AI-powered products without spending two years on linear algebra, that compressed path is exactly the one the market now supports.
**Project:** A CLI tool (Laravel devs will recognize the "artisan command" pattern) that takes a code diff and generates a structured PR description + risk assessment using function calling and Pydantic-validated output.
**Portfolio milestone:** Published npm/pip-installable CLI tool with a demo GIF in the README.

### Phase 3 — RAG & Retrieval Systems (8–10 weeks)
**Objectives:** Build a production-grade retrieval pipeline, not a notebook demo.
**Skills:** Chunking strategies, dense vs sparse retrieval, hybrid search, re-ranking, pgvector or Pinecone, evaluation (RAGAS), hallucination reduction techniques, basic knowledge graphs.
**Why it matters:** RAG in 2026 is iterative — the agent searches, evaluates if information is sufficient, searches again, validates sources — and this requires deep understanding of vector DBs, embedding models, chunking, and retrieval evaluation, plus the ability to design agents that know when they don't know enough.
**Project:** "AI Documentation Assistant for Laravel" — ingest Laravel's own docs + your past project codebases into a RAG system with hybrid search and citation-backed answers, deployed with a FastAPI backend.
**Portfolio milestone:** Live deployed demo + a write-up showing before/after hallucination-rate numbers from your RAGAS eval suite (this single artifact — *measured* eval improvement — is worth more than five chatbot demos).

### Phase 4 — Agentic AI Core (10–12 weeks) — the centerpiece phase
**Objectives:** Build real multi-step, tool-using, stateful agents — this is where you become hireable as "Agentic AI Engineer" specifically.
**Skills:** Agent loops (plan → act → observe → reflect), tool use, memory (short-term/long-term), CrewAI (role-based teams), LangGraph (state machines, durable execution, human-in-the-loop), MCP (build your own MCP server), basic A2A concepts, long-running agent patterns, guardrails/budget caps.
**Why it matters:** Production agents carry state across tool calls, hold memory, watch their own trajectories for failure, retry with different strategies, and enforce budget caps — none of that is prompt engineering, and it's exactly the systems-design instinct backend engineers already have.
**Project:** Multi-agent **AI Customer Support system** (mirrors your real Laravel experience: auth, payment, ticketing): one CrewAI "triage" agent routes to specialist sub-agents (billing — which can query your payment-gateway knowledge, technical, escalation), with a LangGraph state machine enforcing escalation rules and a human-in-the-loop approval step for refunds over a threshold.
**Portfolio milestone:** This is your flagship project. Deploy it, write an architecture doc (tool vs sub-agent vs hardcoded-path decisions, like an AI Agent Architect would), and record a 3-minute Loom walkthrough.

### Phase 5 — AI Infrastructure, DevOps & Cloud (8–10 weeks)
**Objectives:** Make your agents production-survivable: observable, cost-controlled, secure, deployable at scale.
**Skills:** Kubernetes basics, Redis (agent memory/caching), Celery/Kafka (async pipelines), observability/tracing (LangSmith, Phoenix, or AgentOps), prompt/model versioning, CI/CD for AI apps, cost monitoring, one cloud platform deep (AWS Bedrock+SageMaker recommended), prompt injection defense, OWASP for LLMs.
**Why it matters:** 27.2% of agentic jobs require Kubernetes — these are production infrastructure roles, not prototype toolkits. AI security (defending against prompt injection, adversarial attacks) is named as a top emerging skill for 2026+.
**Project:** Take your Phase 4 customer-support agent and add: full observability dashboard (trace every tool call + cost-per-conversation), a CI/CD pipeline that runs your eval suite on every PR before merge, and basic prompt-injection defenses with logged red-team test cases.
**Portfolio milestone:** A "before/after" cost-and-latency report showing how monitoring and caching cut your per-conversation cost — this is the artifact that gets you past the "can you operate this in production" interview question.

### Phase 6 — Specialization + Senior-Track Polish (ongoing, 10+ weeks)
**Objectives:** Pick ONE specialization to go deep on (voice agents, browser/computer-use agents, or multi-agent enterprise platforms) and build your capstone.
**Skills:** Pick based on target companies — voice agents (ElevenLabs + real-time pipelines) if targeting consumer/support AI; browser/computer-use agents if targeting dev-tools companies (Cursor, Windsurf, Replit-adjacent); enterprise multi-agent orchestration if targeting Anthropic/Salesforce/enterprise-AI track.
**Why it matters:** The AI engineering talent market in 2026 rewards specialization — generalists face increasing competition from domain experts who command salaries 30-50% higher for equivalent experience.
**Capstone project:** A multi-agent enterprise system (your strongest fit, given backend depth) — e.g., "AI-powered SaaS Ops Platform" combining RAG (knowledge base), agents (provisioning, billing, support triage via MCP-connected tools to a real Laravel/Postgres backend you build), human-in-the-loop approvals, full observability, and a public architecture writeup.
**Portfolio milestone:** This capstone + your 3 other projects + GitHub contribution history (see Section 8) form your interview portfolio.

---

## 5. Resources Per Major Topic (video-first, as requested)

> Format: **Free video** · Paid course · Docs · Practice. I'm intentionally not padding every micro-topic with 10 categories each — that produces noise. These are the highest-signal picks per phase.

**Python/FastAPI/Async/Docker:**
Free: "FastAPI Course" — freeCodeCamp (YouTube, ~7hrs); "Python Async" — ArjanCodes channel (excellent for engineers coming from typed/structured languages like PHP); Docker — "Docker Crash Course" by TechWorld with Nana.
Paid (high ROI): Talk Python Training's FastAPI course.
Docs: fastapi.tiangolo.com, docs.docker.com.
Practice: Build the Phase 1 project; Exercism's Python track for drills.

**LLM Fundamentals:**
Free: 3Blue1Brown's "Neural Networks/Transformers" series (intuition, not math-heavy — perfect depth for you); Andrej Karpathy's "Let's build GPT" (optional, deeper dive if curious); DeepLearning.AI short courses (free, ~1-2hrs each) on prompt engineering, function calling, and building with Anthropic/OpenAI APIs.
Docs: platform.openai.com/docs, docs.anthropic.com (also has Anthropic Academy — free, hands-on, Claude-specific).
Practice: Anthropic Academy interactive tutorials.

**RAG & Retrieval:**
Free: DeepLearning.AI's "Building and Evaluating Advanced RAG" (short course, free); LangChain Academy's RAG modules.
Docs: LangChain RAG docs, RAGAS docs (evaluation framework).
Practice: Kaggle notebooks on retrieval; build Phase 3 project against your own document set.

**Agentic AI / LangGraph / CrewAI / MCP:**
Free: **LangChain Academy's "Introduction to LangGraph"** — covers agent architectures, state management, tool use, and multi-agent patterns not covered in depth anywhere else for free, taught by the framework's own creators. CrewAI's official YouTube tutorials + crewai.com docs (free, project-based). Anthropic's official MCP documentation + "MCP Inspector" tool for debugging your own servers.
Paid: DeepLearning.AI's "AI Agents in LangGraph" (short, project-based, strong ROI).
Docs: langchain-ai.github.io/langgraph, docs.crewai.com, modelcontextprotocol.io.
Practice: Build an MCP server for one of your own tools (e.g., wrap a Laravel API endpoint as an MCP tool) — this single exercise teaches you the protocol better than any course.

**Vector Databases:**
Free: Pinecone's official YouTube channel; pgvector GitHub README + "Postgres for AI" talks (conference recordings — search YouTube for recent pgvector conference talks).
Practice: Implement the same RAG pipeline against both pgvector and Pinecone to feel the tradeoffs directly.

**AI Infrastructure / Observability / DevOps:**
Free: TechWorld with Nana's Kubernetes course; LangSmith's official docs + YouTube walkthroughs; Arize Phoenix open-source observability tutorials.
Docs: LangSmith docs, Arize Phoenix docs, AWS Bedrock documentation.
Practice: AWS Skill Builder (free tier), Cloud Skills Boost (GCP).

**Security:**
Free: OWASP "Top 10 for LLM Applications" (read the doc, it's short and essential — this is the one place text beats video); search YouTube for recent conference talks on prompt injection (DEF CON AI Village talks are excellent and recorded).

**Practice platforms (ranked by relevance to you):**
1. GitHub (your real portfolio — most important)
2. Hugging Face Spaces (deploy demos for free)
3. Kaggle (datasets + free GPU notebooks for experimentation)
4. AWS Skill Builder / Google Cloud Skills Boost (free cloud practice)
5. LeetCode/HackerRank (only if a specific employer's interview process requires it — not core to agentic roles, deprioritize)

**Communities:** r/LocalLLaMA and r/LangChain (Reddit), LangChain Discord, MCP Discord (via Anthropic/modelcontextprotocol.io), Latent Space newsletter/podcast (swyx — literally coined "AI Engineer" as a role), The AI Engineer newsletter.

---

## 6. Certifications — Ranked Honestly (Don't Over-Invest Here)

The clearest 2026 guidance from practitioners: a portfolio of 3-5 real LLM projects out-performs any premium certification on its own for most roles, and the ideal combo is free courses + one cloud cert + projects.

### Free, worth doing
- **Anthropic Academy** — free, Claude-focused, hands-on. Small lift alone (~10%), but 40%+ callback lift when paired with a portfolio of Claude-based projects — pair it with your Phase 4/6 projects.
- **LangChain Academy** — free, the best agent-specific credential available, taught by the framework creators.
- DeepLearning.AI short courses — free, excellent learning, but don't list them as "certifications" on a resume — they don't issue official certificates, just accomplishments.

### Paid, worth it (pick ONE cloud cert max)
- **AWS Certified AI Practitioner** ($100) → then **AWS Certified Machine Learning Engineer – Associate** ($150) or the new **AWS Certified Generative AI Developer – Professional** ($300) once you're cloud-deploying agents. 30-40% callback lift specifically at AWS-stack enterprises, no lift elsewhere — pick this if targeting US enterprise.
- **Google Cloud Professional Machine Learning Engineer** ($200) — 30% lift across the board because it signals broader ML skill, not just prompting; strongest at infrastructure-heavy teams. Most technically demanding cloud cert, 3-4 months prep.
- Skip Azure AI-900/AI-102 unless your target employer is explicitly Microsoft-stack — AI-900 retires June 30, 2026 anyway, replaced by AI-901.

### Skip / low ROI for you specifically
- Generic "Prompt Engineering Certification" badges with no official backing — recruiters won't recognize any credential with fewer than ~1,000 LinkedIn holders.
- AWS ML Specialty — being retired/superseded by MLA-C01.
- Any "Certified AI Scientist"-style vendor badge from non-major providers (USAII, ARTIBA, etc.) — low employer recognition relative to cost.
- NVIDIA DLI certs — only relevant if you pivot toward GPU infra/edge AI, which is explicitly NOT your stated goal.

---

## 7. Executive Programs / Degrees — Honest Verdict

Given your goal (production AI *engineer*, not researcher) and stated learning style (video > books), **a formal degree is NOT recommended as your near-term move.** Here's the honest comparison if you're tempted:

| Program | Fees (approx) | Duration | Verdict for YOU |
|---|---|---|---|
| Stanford/MIT/CMU online certificates | $2,300–$23,000 | 3–12 months | Save these for senior engineers aiming at research roles, PhD programs, or executive AI leadership — not where you are now. Skip for now. |
| IIT/IIIT/BITS executive PG (WILP, etc.) | ₹2–6L | 18–24 months | Decent brand signal in India specifically, but slow relative to portfolio-building; only worth it if you specifically need the credential for a visa/government-adjacent role. Low priority. |
| MIT Professional Education ML/AI cert | ~$3,500 | Self-paced, months | In-depth, customizable, for experienced professionals — revisit at month 18+ if you want a leadership-track credential, not before. |
| Vanderbilt Prompt Engineering Specialization (Coursera) | ~$49 | 2–3 weeks | The most broadly recognized non-technical-facing credential, clears ATS keyword filters — fine as a cheap add-on, not a centerpiece. |

**Bottom line:** At 5-10 hrs/week, every hour spent on a $15K certificate is an hour not spent shipping the 4 portfolio projects that actually get you interviews in this market. Revisit formal programs only after you have a job offer and are thinking about the *next* level (Staff/Architect), 18-24 months from now.

---

## 8. Portfolio Roadmap

| Level | Project | Maps to Phase |
|---|---|---|
| Beginner | FastAPI port of a Laravel service + Docker | Phase 1 |
| Beginner | Structured-output CLI tool (function calling) | Phase 2 |
| Intermediate | RAG documentation assistant with measured eval scores | Phase 3 |
| Advanced | Multi-agent AI Customer Support (CrewAI + LangGraph + MCP + human-in-the-loop) | Phase 4 |
| Advanced | Observability/cost-optimization layer on the above | Phase 5 |
| **Capstone** | Multi-agent Enterprise SaaS Ops Platform (RAG + agents + MCP-connected real backend + full observability) | Phase 6 |

For each: push to GitHub with a real README (architecture diagram, tradeoffs, what you'd do differently at scale), deploy a live demo (Railway/Fly.io/Vercel), and write a short LinkedIn/blog post per project — hiring managers in this market are explicitly looking for "demonstrated AI fluency via portfolio: built agents, shipped automations, published evals."

---

## 9. Interview Preparation Roadmap

- **Python + SQL:** Standard mid-level bar — don't over-invest, you'll clear this given your backend background; light Exercism practice is enough.
- **System Design (general):** Your existing backend architecture experience transfers directly — practice articulating tradeoffs out loud.
- **AI System Design (the new bar):** Practice designing: a RAG system at scale, an agent system with failure-mode handling, a cost-controlled multi-tenant LLM gateway. This is the differentiator round for agentic roles — your Phase 4-6 projects ARE your prep material.
- **LLM/RAG/Agentic questions:** Be ready to explain chunking tradeoffs, when to use hybrid vs dense retrieval, why/when you'd choose CrewAI vs LangGraph, MCP vs A2A, and how you'd debug a hallucinating or runaway agent in production.
- **Behavioral:** Frame every backend-engineering story (auth systems, payment integration, deployment incidents) as evidence of production-reliability thinking — that's exactly what agentic hiring managers are screening for per the research above.

---

## 10. The 6-Phase Schedule at 5–10 hrs/week

| Phase | Duration | Weekly rhythm (example) |
|---|---|---|
| 1. Python/FastAPI | 6–8 wks | 2 weekday evenings (1.5hr video+coding) + 1 weekend block (3-4hr project work) |
| 2. LLM Foundations | 6–8 wks | Same rhythm |
| 3. RAG | 8–10 wks | Same rhythm; weekend block extends to cover eval-suite building |
| 4. Agentic Core | 10–12 wks | This is your flagship — consider a weekend "sprint day" every 2 weeks |
| 5. Infra/DevOps | 8–10 wks | Same rhythm |
| 6. Specialization/Capstone | 10+ wks, ongoing | Weekend-heavy; this overlaps with starting to apply |

**Total: ~48–58 weeks (11–13.5 months) of curriculum**, plus a realistic 4-8 week buffer for life/work disruptions → **~13-16 months to a strong, interview-ready portfolio**, with job applications starting around month 9-10 once Phase 4 is done (agentic hiring managers care most about the flagship multi-agent project, not 100% curriculum completion).

**Monthly rhythm:** 1 week revision/catch-up buffer per phase boundary. **Certification schedule:** Anthropic Academy + LangChain Academy during Phase 4 (free, fits naturally); one cloud cert during Phase 5. **Portfolio schedule:** ship one project at the end of each phase, not all at the end — recruiters can see your GitHub commit history and "ships things continuously" is itself a strong signal.

---

## Final note

You don't need to memorize all 50+ named technologies in your original brief — that's the over-collection trap the research explicitly warns against ("a resume listing LangChain + Pinecone + a fine-tuning experiment that never left a notebook" is now a yellow flag, not a green one). The market in 2026 rewards depth in the agentic core (Phase 4) backed by one solid cloud deployment story (Phase 5) over shallow exposure to everything on the list. Your backend background is the asset — lean into "I build reliable systems, and now the systems happen to call LLMs" as your narrative, not "I am learning AI from scratch."
