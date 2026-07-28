# HN9 AI Studio — Brand Brain Guidelines

> **Company:** HN9 Codecraft · **Brand:** HN9 AI Studio · **Website:** https://hn9.io
> **Tagline:** *All your digital needs under one roof!!!*
> **Version:** 1.0.0 · **Last updated:** 2026-07-18

This document is the human-readable companion to the **Brand Brain** — the JSON data layer in this
folder that every HN9 AI Agent reads before generating any content, design, video or copy.

The JSON files are the **machine source of truth**. This Markdown file explains what each one is
for, how they relate, and how agents and humans should use them. **Whenever a JSON file changes,
update this document to match.**

---

## Design Principles

1. **Single source of truth** — every brand fact lives in exactly one JSON file. Nothing is
   duplicated or hard-coded elsewhere.
2. **Normalized & referenced** — files reference each other by filename/key instead of copying data.
3. **Machine-readable first** — JSON holds data; comments and explanations live only in Markdown.
4. **Scalable** — new services, personas, CTAs or platforms are added as array entries, no
   structural changes required.
5. **Agent-ready** — any future AI Agent can consume these files without modification.

---

## Load Order for AI Agents

Agents should load files in this order (also encoded in `ai-rules.json > loadOrder`):

1. `ai-rules.json` — the non-negotiable rules
2. `brand.json` — brand identity
3. `company.json` — company facts
4. `tone.json` — how to sound
5. `content-rules.json` — what is/ isn't allowed
6. `services.json` — what HN9 actually offers
7. `cta.json` — how to close

All other files (`colors`, `fonts`, `seo`, `video-style`, etc.) are loaded on demand based on the
task.

---

## File-by-File Reference

### `ai-rules.json` — **Read this first**
The master rulebook. Contains `mustFollow` and `mustNever` lists, brand-consistency constants, the
source-of-truth map, load order, conflict-resolution precedence, and the human-review policy. Every
agent must obey this file above all others (except an explicit human override).

### `brand.json`
Complete brand identity: brand/legal names, tagline, website, logo paths & clear-space rules, brand
colors, industry, positioning, value proposition, personality, brand pillars and differentiators.
Also holds a `references` map pointing to every other Brand file.

### `company.json`
The organization behind the brand: legal name (HN9 Codecraft), founding year, **mission**,
**vision**, **values** (with descriptions), the **company story**, differentiators, headquarters and
**contact placeholders**, registration placeholders, and social profiles.

### `services.json`
The full service catalog. Each service is a normalized object with:
`id`, `name`, `slug`, `category`, `shortDescription`, `longDescription`, `keywords`,
`targetAudience`, and `callToAction`. **Agents must never offer a service that isn't in this file.**

### `colors.json`
The color system: **primary** (#0D365C), **secondary** (#F0A80B), **background** variants,
**status** colors (success, warning, danger, info), **text** colors, and **gradients** (with ready
CSS). Includes accessibility guidance (e.g. amber requires dark text).

### `fonts.json`
Typography system: **heading** (Poppins), **body** (Inter), **mono** (JetBrains Mono), **fallback**
stacks, a full **size** scale (with px/rem/line-height), **weights**, and letter-spacing tokens.

### `audience.json`
Detailed customer **personas** (Startup Founder, Small Business Owner, Growth Lead, Enterprise
Decision Maker). Each persona lists `problems`, `goals`, `painPoints`, `industries`, `companySize`
and preferred channels. Also holds the master `segments`, `companySizes` and `industriesServed`
lists.

### `tone.json`
Voice and tone definitions per context: **writing**, **speaking**, **video**, **social**, **email**
and **SEO** tone — each with `do`/`don't` lists — plus a shared vocabulary of `preferredTerms` and
`avoidTerms`.

### `content-rules.json`
Hard rules for AI-generated content: global rules (no fake promises, professional English, short
paragraphs, strong CTA, no clickbait), formatting limits, prohibited/required items, and a
human-review checklist.

### `video-style.json`
The video language: **camera style**, **animation style**, **transitions**, **background music**
mood/rules, **subtitle rules**, **voice rules**, and the standard **reel structure**
(Hook → Value → Proof → CTA) with aspect ratios.

### `design-system.json`
UI foundations: **spacing** scale, **radius** tokens, **elevation**, **button** variants/sizes,
**card** styles, **icon** style (Lucide line icons), **illustration style**, and the responsive
**grid**.

### `social-media.json`
Per-platform playbook for **Instagram, LinkedIn, Facebook, YouTube, X** — formats, **posting
frequency**, best times, caption guidance and aspect ratios — plus **hashtag** sets/rules and
**emoji rules**.

### `cta.json`
All approved calls to action: **primary** (Book Free Consultation, Schedule Demo, Let's Build
Together, Contact HN9 Today), **secondary**, and **micro** CTAs, with per-channel defaults and usage
rules. **Agents must only use CTAs from this file.**

### `keywords.json`
SEO keyword library: **primary**, **secondary**, **LSI**, **industry**, **branded**, **long-tail**
and **negative** keywords, with usage notes on mapping keywords to page types.

### `seo.json`
SEO output standards: **meta title** and **meta description** templates, **Open Graph** templates,
**schema.org** templates (Organization, Service, Article, Breadcrumb, FAQ), plus **URL**, **slug**,
**heading** and **internal-linking** rules, and technical requirements.

### `brand.json` references & this file
`brand.json` links to every file; this Markdown explains them. Keep the two consistent.

---

## How the Files Work Together

```
                 ai-rules.json  (governs everything)
                        │
        ┌───────────────┼───────────────────────────┐
        ▼               ▼                             ▼
   brand.json      company.json                 services.json
        │                                             │
        ├── colors.json / fonts.json / design-system.json  → visual output
        ├── tone.json / content-rules.json                 → written output
        ├── video-style.json                               → video output
        ├── social-media.json                              → channel output
        ├── keywords.json / seo.json                       → search output
        └── cta.json                                       → every CTA
```

A typical generation flow: an agent loads `ai-rules.json`, pulls identity from `brand.json` /
`company.json`, confirms the offering in `services.json`, writes in the voice from `tone.json` while
respecting `content-rules.json`, applies `colors`/`fonts`/`design-system` for visuals or
`video-style` for video, optimizes with `keywords`/`seo`, and closes with a CTA from `cta.json`.

---

## Governance

- **Editing:** Update the relevant JSON file, bump its `version`/`lastUpdated`, then update this doc.
- **Validation:** All JSON must remain valid and parseable. Do not add comments inside JSON.
- **Placeholders:** Contact and registration fields are intentional placeholders — replace with
  final data before public use; agents must not present placeholders as final.
- **Human review:** All public-facing output requires human review per `content-rules.json` and
  `ai-rules.json`.

---

*This Brand Brain is designed to be read by both humans and AI agents. Keep it accurate — it is the
foundation every piece of HN9 content is built on.*
