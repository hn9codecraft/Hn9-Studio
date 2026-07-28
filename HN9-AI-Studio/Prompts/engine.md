# Prompt Engine — Assembly Specification

This document explains **how the Prompt Engine assembles a final prompt** from reusable parts. It is
the contract every template and every future AI Agent follows.

---

## 1. Core idea

A **template** is a skeleton with placeholders. The engine fills those placeholders from two sources:

- **Brand variables** → resolved from the Brand Brain (`../Brand/*.json`). Never typed by hand.
- **Runtime variables** → provided by the user/agent per request (topic, goal, platform, language…).

The engine then layers **platform rules** and **content rules** on top before emitting the prompt.

---

## 2. Prompt Flow

```
        User Input
            ↓
        Brand Brain            (../Brand — voice, company, services, colors, CTA, rules)
            ↓
       Platform Rules          (social-media.json / video-style.json / seo.json per channel)
            ↓
       Content Rules           (content-rules.json + ai-rules.json — governing constraints)
            ↓
      Prompt Template          (templates/<name>.md skeleton)
            ↓
     Runtime Variables         (bind {{...}} placeholders)
            ↓
       Final Prompt            (validated, ready for the AI Agent)
```

Read top-to-bottom: later stages **refine** and **constrain** earlier ones. `ai-rules.json` sits
above all — it can veto anything.

---

## 3. Resolution order (precedence)

When the same field could come from multiple places, resolve in this order (highest wins):

1. **`../Brand/ai-rules.json`** — hard constraints, may override anything.
2. **Explicit user override** — a runtime variable the user set intentionally.
3. **Platform rules** — channel-specific limits (length, aspect ratio, hashtags).
4. **`../Brand/content-rules.json`** — global content constraints.
5. **Template defaults** — the template's own optional-variable defaults.
6. **Brand Brain defaults** — e.g. default CTA per channel from `cta.json > channelDefaults`.

This mirrors `../Brand/ai-rules.json > conflictResolution`.

---

## 4. Variable binding

Placeholders use `{{snake_case}}`. Each is one of:

| Kind | Resolves from | Example |
|------|---------------|---------|
| **Brand** | a Brand Brain file (read-only) | `{{company_name}}` ← `company.json > displayName` |
| **Runtime** | user input for this request | `{{topic}}`, `{{goal}}` |
| **Derived** | computed from other variables | `{{cta}}` ← `cta.json` filtered by `{{platform}}` |

Full mapping is in [variables.md](variables.md). The engine must **fail loudly** (flag for human
review) if a required Brand variable cannot be resolved — never fabricate it (`ai-rules.json >
onUncertainty`).

---

## 5. Assembly pseudo-flow (documentation, not code)

```
1. Load ../Brand/ai-rules.json           → governing rules + brand constants
2. Load the chosen template              → skeleton + required/optional vars
3. Collect runtime inputs                → validate all Required Variables present
4. Resolve Brand variables by reference  → voice, company, services, colors, CTA, SEO…
5. Apply platform rules for {{platform}} → length, format, aspect ratio, hashtag policy
6. Apply content rules                   → no fake promises, short paragraphs, strong CTA…
7. Bind runtime variables                → fill remaining {{...}}
8. Localize to {{language}}              → en / hi / gu (do NOT translate brand terms)
9. Emit Final Prompt
10. Run Validation Checklist             → block output on any failure
```

---

## 6. Template standard (every file in `templates/`)

Each template documents exactly these nine sections:

1. **Purpose** — what it generates and when to use it.
2. **Inputs** — human-readable list of what the user must supply.
3. **Required Variables** — placeholders that MUST be bound.
4. **Optional Variables** — placeholders with sensible defaults.
5. **Prompt Template** — the actual skeleton with `{{...}}`.
6. **Output Structure** — the shape of the expected result.
7. **Validation Checklist** — pass/fail gates before delivery.
8. **Example Input** — a filled-in sample request.
9. **Example Output** — a representative result.

---

## 7. Language layer

`{{language}}` ∈ { `en`, `hi`, `gu` }. The engine instructs the model to write natural, native-level
copy in that language. **Never translated:** `{{company_name}}`, `{{brand_name}}`, `{{tagline}}`,
product/service proper names, URLs, and CTAs that are registered brand phrases (unless a localized
CTA exists in `cta.json`).

---

## 8. Extensibility for future AI Agents

Agents in `../Agents` consume this engine directly: an agent picks a template, supplies runtime
variables, and receives a Final Prompt. Because Brand data is referenced (not copied), agents always
use current brand truth. To extend the system, add templates/variables — never hardcode brand values.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
