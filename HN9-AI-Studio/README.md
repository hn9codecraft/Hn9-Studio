# HN9 AI Studio

An AI-powered content generation platform that creates **Instagram Reels, YouTube Shorts, LinkedIn
posts, blogs, images, voiceovers, AI videos and marketing assets** — all driven from a single brand
source of truth and orchestrated by a fleet of AI agents.

## Overview

HN9 AI Studio treats content production as a supply chain: a central brand definition feeds a library
of prompts and templates, which are executed by single-responsibility AI agents, chained together by
automation workflows, and delivered as publish-ready output.

## Project Structure

```
HN9-AI-Studio/
├── Brand/           Brand source of truth (identity, colors, fonts, tone, audience)
├── Prompts/         Version-controlled prompt library, by channel & technology
├── Scripts/         Approved, production-ready content scripts
├── Characters/      Consistent AI avatars & mascot definitions
├── Images/          Still-image assets (generated & curated)
├── Videos/          Video assets across the production pipeline
├── Voice/           Voiceovers, music, SFX and TTS renders
├── Logos/           Logo assets for HN9, clients, tools & platforms
├── Templates/       Reusable content-layout templates per channel
├── Agents/          AI agent fleet definitions (one responsibility each)
├── Workflows/       Automation definitions (n8n, Make) and docs
├── Assets/          Shared raw source assets (fonts, music, stock)
├── Output/          Final, delivery-ready output
├── Dashboard/       Operational dashboard / admin app (reserved)
├── Backend/         Backend services & orchestration (reserved)
├── Frontend/        Client-facing web application (reserved)
└── Documentation/   Roadmap, architecture, API, workflow & standards
```

## Documentation

See [Documentation/](Documentation/):
- [Project Roadmap](Documentation/Project%20Roadmap.md)
- [Architecture](Documentation/Architecture.md)
- [API](Documentation/API.md)
- [Workflow](Documentation/Workflow.md)
- [Prompt Standards](Documentation/Prompt%20Standards.md)

## Conventions

- **Single source of truth** — brand data lives once in [Brand/](Brand/).
- **Single-responsibility agents** — each agent in [Agents/](Agents/) does one thing.
- **Source vs output** — inputs live in [Assets/](Assets/); deliverables in [Output/](Output/).
- **Documented folders** — every folder carries a `README.md` describing its purpose.
- **Scalable by convention** — new agents/channels follow the existing folder patterns.

## Status

Project scaffolding only — this repository currently defines the structure and placeholder
templates. Application code lives under `Backend/`, `Frontend/` and `Dashboard/` as they are built.

## License

See [LICENSE](LICENSE).
