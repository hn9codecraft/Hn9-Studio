# HN9 AI Studio — Multi-Agent AI System

Documentation-only specification for the **production-grade Multi-Agent AI System** that generates
all HN9 content: Instagram posts & reels, LinkedIn/Facebook posts, blogs, website & landing-page
copy, sales emails, proposals, SEO content, YouTube scripts, storyboards, AI image/video prompts,
voice scripts, captions and hashtags.

> This milestone contains **no application code** — only architecture, specifications, contracts,
> workflows and reusable assets.

---

## Source-of-truth rule

There are exactly two sources of truth. Agents **read** from them and **never duplicate** them:

| Source | Location | What it provides |
|--------|----------|------------------|
| **Brand Brain** | `../Brand` | Identity, voice, services, colors, CTA, SEO/content/AI/video rules |
| **Prompt Engine** | `../Prompts` | Reusable, platform-aware prompt templates + variable contracts |

Every agent references these by path/key. If required data is missing, an agent **flags for human
review** — it never fabricates (`../Brand/ai-rules.json > onUncertainty`).

---

## Folder structure

```
Agents/
├── README.md                     ← this file
├── architecture.md               ← why multi-agent; scalability, coupling, cloud/queue/API-ready
├── orchestrator.md               ← how agents are called; order, parallel/sequential/conditional
├── execution-pipeline.md         ← full end-to-end workflow diagram
├── handoff-rules.md              ← how one agent hands off to the next
├── communication-protocol.md     ← input/output contracts, shared context, JSON standards
├── validation.md                 ← the validation pipeline (brand/grammar/SEO/platform/...)
├── error-handling.md             ← failure modes and recovery flows
├── memory.md                     ← short-term / project / brand / prompt / runtime memory
├── logging.md                    ← what every agent must log
├── security.md                   ← prompt-injection, brand & data protection, hallucination
├── performance.md                ← execution goals, caching, parallelism, queues
├── versioning.md                 ← semantic versioning for agents/prompts/workflows
├── testing.md                    ← unit / integration / workflow / regression / manual QA
└── agents/                       ← one contract document per agent (17)
    ├── planner-agent.md          ├── voice-agent.md        ├── blog-agent.md
    ├── research-agent.md         ├── caption-agent.md      ├── social-agent.md
    ├── strategy-agent.md         ├── thumbnail-agent.md    ├── review-agent.md
    ├── seo-agent.md              ├── image-prompt-agent.md ├── qa-agent.md
    ├── script-agent.md           ├── video-prompt-agent.md ├── publisher-agent.md
    └── storyboard-agent.md                                 └── analytics-agent.md
```

> Relationship to existing folders: the PascalCase folders (`Planner/`, `Research/`, …) hold each
> agent's runtime **config** (`agent.json`) and outputs. The `agents/` subfolder here holds each
> agent's **architecture contract**. Config = *what to run*; contract = *how it behaves*.

---

## Design goals (enforced across all docs)

- **Independently replaceable** agents with **clear contracts** (loose coupling, high cohesion).
- **Event-driven**, **queue-ready**, **API-ready**, **cloud-ready**.
- **AI-provider abstraction** — no agent is tied to one model vendor.
- **Human-approval** gates before publishing.
- **Multi-language** (`en`, `hi`, `gu`) end to end.
- **Production-ready**: observability, security, versioning, testing.

---

## Reading order

1. `architecture.md` → `orchestrator.md` → `execution-pipeline.md`
2. Contracts: `communication-protocol.md`, `handoff-rules.md`
3. Cross-cutting: `validation.md`, `error-handling.md`, `security.md`, `memory.md`, `logging.md`,
   `performance.md`, `versioning.md`, `testing.md`
4. Per-agent contracts in `agents/`

**Version:** 1.0.0 · **Last updated:** 2026-07-18
