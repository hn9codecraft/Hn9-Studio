# Prompt Engine — Variables

Every reusable placeholder used across templates. Placeholders use `{{snake_case}}`.

- **Brand variables** are resolved **by reference** from `../Brand/*.json`. Never hardcode them.
- **Runtime variables** are supplied per request by the user or agent.
- **Derived variables** are computed from other variables + Brand Brain.

If a **required** variable cannot be resolved, the engine must stop and flag for human review
(`../Brand/ai-rules.json > onUncertainty`). It must never invent a value.

---

## 1. Brand variables (resolved from `../Brand`)

| Variable | Resolves from | Notes |
|----------|---------------|-------|
| `{{company_name}}` | `company.json > legalName` | Never change or translate. |
| `{{brand_name}}` | `brand.json > brandName` | Public brand name. |
| `{{short_name}}` | `brand.json > brandName` (short) | e.g. abbreviated brand. |
| `{{tagline}}` | `brand.json > tagline` | Never translate. |
| `{{website}}` | `brand.json > website` | |
| `{{primary_color}}` | `colors.json > primary.hex` | |
| `{{secondary_color}}` | `colors.json > secondary.hex` | |
| `{{background_color}}` | `colors.json > background` | Light/subtle/dark variants. |
| `{{text_color}}` | `colors.json > text.primary.hex` | |
| `{{gradient}}` | `colors.json > gradients` | CSS-ready. |
| `{{heading_font}}` | `fonts.json > heading.family` | |
| `{{body_font}}` | `fonts.json > body.family` | |
| `{{service}}` | `services.json > services[].name` | Must exist in file. |
| `{{service_slug}}` | `services.json > services[].slug` | |
| `{{service_description}}` | `services.json > services[].longDescription` | |
| `{{service_keywords}}` | `services.json > services[].keywords` | |
| `{{audience}}` | `audience.json > personas[]` / `segments` | Persona or segment. |
| `{{audience_painpoints}}` | `audience.json > personas[].painPoints` | |
| `{{audience_goals}}` | `audience.json > personas[].goals` | |
| `{{tone}}` | `tone.json` (context-specific) | See `{{tone_context}}`. |
| `{{tone_context}}` | selects `tone.json` block | `writingTone` \| `socialTone` \| `videoTone` \| `emailTone` \| `seoTone` \| `speakingTone`. |
| `{{preferred_terms}}` | `tone.json > vocabulary.preferredTerms` | |
| `{{avoid_terms}}` | `tone.json > vocabulary.avoidTerms` | |
| `{{cta}}` | `cta.json` (by `{{platform}}`) | See derived rules below. |
| `{{brand_rules}}` | `content-rules.json` + `ai-rules.json` | Injected constraint block. |
| `{{seo_rules}}` | `seo.json` | Titles, meta, slugs, headings, linking. |
| `{{keywords}}` | `keywords.json` (+ `{{service_keywords}}`) | See derived rules. |
| `{{video_style}}` | `video-style.json` | Camera, transitions, reel structure. |
| `{{animation_style}}` | `video-style.json > animationStyle` | |
| `{{voice_style}}` | `video-style.json > voiceRules` | + `../Voice/ElevenLabs`. |
| `{{subtitle_rules}}` | `video-style.json > subtitleRules` | |
| `{{music_style}}` | `video-style.json > backgroundMusic` | |
| `{{design_system}}` | `design-system.json` | Spacing, radius, buttons, cards. |
| `{{hashtag_policy}}` | `social-media.json > hashtags` | Per-platform limits. |
| `{{emoji_policy}}` | `social-media.json > emojiRules` | |
| `{{posting_spec}}` | `social-media.json > platforms[{{platform}}]` | Formats, aspect ratios. |

---

## 2. Runtime variables (supplied per request)

| Variable | Meaning | Example |
|----------|---------|---------|
| `{{platform}}` | Target channel | `instagram`, `linkedin`, `youtube`, `blog`, `email` |
| `{{language}}` | Output language | `en`, `hi`, `gu` |
| `{{topic}}` | Subject of the content | "AI automation for small business" |
| `{{goal}}` | Desired outcome | `lead`, `awareness`, `demo`, `traffic`, `engagement` |
| `{{duration}}` | Length for video/audio | `30s`, `60s`, `3min` |
| `{{word_count}}` | Length for text | `1500` |
| `{{key_points}}` | Points to cover | list of bullets |
| `{{offer}}` | Optional promo/offer | "Free audit" |
| `{{recipient}}` | Email/sales recipient | "Founder of a SaaS startup" |
| `{{aspect_ratio}}` | Media aspect | `9:16`, `16:9`, `1:1`, `4:5` |
| `{{scene_count}}` | Storyboard/video scenes | `5` |
| `{{style_reference}}` | Visual reference | "modern, minimal, geometric" |
| `{{keywords_focus}}` | Primary keyword override | "custom crm development" |

---

## 3. Derived variables (computed)

| Variable | Rule |
|----------|------|
| `{{cta}}` | If user set a CTA id, use it. Else `cta.json > channelDefaults[{{platform}}]`. Must be an approved CTA. |
| `{{keywords}}` | Merge `{{keywords_focus}}` (if any) + matching `{{service_keywords}}` + relevant `keywords.json` sets. De-duplicate. |
| `{{tone}}` | Select the `tone.json` block matching `{{tone_context}}`, which defaults per template (e.g. social templates → `socialTone`). |
| `{{cta_url}}` | The `url` of the resolved `{{cta}}` from `cta.json`. |
| `{{aspect_ratio}}` | If unset, default from `social-media.json`/`video-style.json` for `{{platform}}`. |

---

## 4. Core variable quick list (as required by spec)

```
{{company_name}}   {{tagline}}         {{primary_color}}   {{secondary_color}}
{{service}}        {{audience}}        {{language}}        {{tone}}
{{cta}}            {{duration}}        {{platform}}        {{goal}}
{{keywords}}       {{video_style}}     {{animation_style}} {{voice_style}}
{{brand_rules}}
```

---

## 5. Conventions

- Names are lowercase `snake_case` inside `{{ }}`.
- Brand variables are **read-only**; templates must not redefine their values.
- Missing required variable → **block and flag**, never guess.
- Unknown variable in a template → treat as authoring error.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
