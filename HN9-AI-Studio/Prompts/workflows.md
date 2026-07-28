# Prompt Engine — Workflows

A **workflow** chains templates and reasoning steps to produce a complete deliverable. Each step is
a prompt assembled by the engine (see [engine.md](engine.md)). Workflows are consumed by the AI
Agents in `../Agents` (Planner → Script Writer → … → Publisher).

Every workflow inherits the Brand Brain by reference and ends with a Brand-approved `{{cta}}`.

---

## Instagram Reel

```
Topic
  ↓
Hook          (0–3s — bold problem/promise, videoTone)
  ↓
Problem       (the pain from audience.json)
  ↓
Solution      (map to a real service from services.json)
  ↓
Benefits      (outcome-focused, no fake promises)
  ↓
CTA           (cta.json → channelDefaults.instagram)
```

**Templates used:** `reel.md` → `script.md` → `caption.md` → `hashtags.md` → `voice.md` →
`thumbnail.md`
**Key rules:** 9:16, 15–45s, subtitle rules, Hook → Value → Proof → CTA.

---

## LinkedIn Post

```
Hook          (scroll-stopping first line, writingTone)
  ↓
Story         (relatable context or mini case)
  ↓
Value         (insight / lesson / how-to)
  ↓
CTA           (cta.json → channelDefaults.linkedin)
```

**Templates used:** `linkedin.md` (+ optional `carousel.md` for document posts)
**Key rules:** professional tone, 3–5 hashtags, minimal emojis.

---

## Blog

```
Title         (SEO title template from seo.json)
  ↓
Outline       (H1/H2/H3 hierarchy, keyword mapped)
  ↓
Article       (writingTone, short paragraphs)
  ↓
SEO           (meta title/description, slug, internal links)
  ↓
CTA           (cta.json → channelDefaults.blog)
```

**Templates used:** `blog.md` → `seo.md` → `caption.md` (for promotion)
**Key rules:** one H1, natural keywords, no clickbait, internal linking rules.

---

## YouTube

```
Hook          (first 3–5s retention hook)
  ↓
Script        (structured narration, videoTone)
  ↓
Scenes        (storyboard — shots, b-roll, on-screen text)
  ↓
CTA           (cta.json → channelDefaults.youtube: subscribe + primary)
```

**Templates used:** `youtube.md` → `script.md` → `storyboard.md` → `thumbnail.md` → `voice.md` →
`caption.md`
**Key rules:** 16:9 (long) / 9:16 (Shorts), end card with logo + tagline + CTA.

---

## Website / Landing Page

```
Goal
  ↓
Hero          (headline + subhead + primary CTA)
  ↓
Sections      (services, proof, benefits — from services.json)
  ↓
SEO           (meta, schema, headings from seo.json)
  ↓
CTA           (primary + secondary from cta.json)
```

**Templates used:** `website.md` / `landing-page.md` → `seo.md`

---

## Email / Sales

```
Recipient + Goal
  ↓
Subject Line  (concise, no spam words)
  ↓
Body          (emailTone, personal, one idea)
  ↓
CTA           (cta.json → channelDefaults.email)
```

**Templates used:** `email.md` / `sales.md`

---

## Proposal

```
Client + Scope
  ↓
Understanding (restate client's problem)
  ↓
Solution      (real services from services.json)
  ↓
Value + Approach
  ↓
CTA           (Schedule Demo / Let's Build Together)
```

**Templates used:** `proposal.md`

---

## Visual assets (Image / Video / Thumbnail)

```
Concept
  ↓
Style         (design-system.json / video-style.json, brand colors + fonts)
  ↓
Prompt        (image.md / video.md / thumbnail.md)
  ↓
Review        (on-brand check)
```

---

## Workflow composition rules

- Each step is a separate engine assembly; outputs of one step become inputs to the next.
- Language `{{language}}` stays constant across a workflow unless explicitly changed.
- The **final** user-facing step must contain exactly one primary `{{cta}}`.
- Every workflow output passes its templates' Validation Checklists before publishing.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
