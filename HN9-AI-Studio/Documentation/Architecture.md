# Architecture — HN9 AI Studio

## High-Level Overview
HN9 AI Studio is organized as a **content supply chain** driven by a fleet of single-responsibility
AI agents, fed by a central brand source of truth, and orchestrated by automation workflows.

```
Brand (source of truth)
        │
        ▼
Prompts ──► Agents ──► Workflows ──► Output
   ▲           │            │           │
Templates   Characters   Assets     Publishing
```

## Layers

| Layer | Folder(s) | Responsibility |
|-------|-----------|----------------|
| Identity | `Brand`, `Characters`, `Logos` | Who HN9 is and how it looks/sounds. |
| Knowledge | `Prompts`, `Templates`, `Scripts` | Reusable instructions and structures. |
| Intelligence | `Agents` | Single-responsibility AI units. |
| Orchestration | `Workflows` | Chaining agents and tools end-to-end. |
| Media | `Images`, `Videos`, `Voice`, `Assets` | Raw and generated media. |
| Delivery | `Output` | Publish-ready deliverables. |
| Application | `Backend`, `Frontend`, `Dashboard` | Software surfaces. |

## Design Principles
- **Single source of truth** — brand data lives once, in `/Brand`.
- **Single-responsibility agents** — each agent does one thing well.
- **Composable workflows** — agents are chained, not monolithic.
- **Separation of source and output** — `/Assets` (input) vs `/Output` (delivered).
- **Scalable by convention** — new agents/channels follow existing folder patterns.

_Diagrams and component details are placeholders — expand as the system is built._
