# HN9 AI Studio — Prompt Engine

This is **not** a prompt collection. It is a **Dynamic Prompt Engine**: a documented system that
assembles production-ready prompts at runtime by combining the **Brand Brain** (`../Brand`) with
per-request user inputs.

> **Golden rule:** Brand information is **never duplicated here — only referenced.** The Brand Brain
> in `../Brand` is the single source of truth. This engine reads it; it does not restate it.

---

## What this engine does

```
Brand Brain (../Brand)  +  User Inputs  ──►  Prompt Engine  ──►  Final Prompt  ──►  AI Agent
```

Every template inherits, by reference, the following from the Brand Brain:

| Inherited | Source file (in `../Brand`) |
|-----------|------------------------------|
| Brand voice & tone | `tone.json` |
| Company information | `company.json`, `brand.json` |
| Services | `services.json` |
| Colors | `colors.json` |
| CTAs | `cta.json` |
| SEO rules | `seo.json`, `keywords.json` |
| Content rules | `content-rules.json` |
| AI rules (governing) | `ai-rules.json` |
| Video rules | `video-style.json` |
| Design system | `design-system.json` |
| Social rules | `social-media.json` |
| Audience personas | `audience.json` |
| Fonts | `fonts.json` |

---

## Folder structure

```
Prompts/
├── README.md          ← you are here — overview & index
├── engine.md          ← how prompts are assembled (the flow)
├── variables.md       ← every reusable variable and how it resolves
├── rules.md           ← prompt construction rules
├── workflows.md       ← end-to-end content workflows
└── templates/         ← 21 platform-aware prompt templates
    ├── instagram.md   ├── landing-page.md  ├── carousel.md
    ├── linkedin.md    ├── email.md         ├── reel.md
    ├── facebook.md    ├── sales.md         ├── thumbnail.md
    ├── youtube.md     ├── proposal.md      ├── image.md
    ├── blog.md        ├── script.md        ├── video.md
    ├── seo.md         ├── storyboard.md    ├── voice.md
    ├── website.md     ├── caption.md       └── hashtags.md
```

> Note: the legacy channel folders (`Instagram/`, `LinkedIn/`, …) remain for storing generated
> prompt outputs. The engine's reusable **templates** live in `templates/`.

---

## How to use (for humans & agents)

1. Read `rules.md` and `../Brand/ai-rules.json` first — they govern everything.
2. Pick a template from `templates/`.
3. Provide the template's **Required Variables** (see `variables.md`).
4. The engine resolves Brand variables from `../Brand`, applies platform + content rules, and emits
   the **Final Prompt** (see `engine.md`).
5. Validate the output against the template's **Validation Checklist**.

---

## Language support

Every template is **platform-aware** and supports three output languages via `{{language}}`:

- `en` — English
- `hi` — Hindi (हिन्दी)
- `gu` — Gujarati (ગુજરાતી)

Brand names, product names and the tagline are **never translated** (see `rules.md`).

---

## Scalability

Adding a new template = adding one Markdown file to `templates/` following the 9-section standard in
`engine.md`. Adding a new variable = one entry in `variables.md`. No Brand data is copied, so a
change in `../Brand` instantly propagates to every prompt. This keeps the engine ready for future AI
Agents in `../Agents`.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
