# Prompt Engine — Construction Rules

These rules govern **how prompts are built and what generated output must obey**. They inherit and
never contradict `../Brand/ai-rules.json`, which is the supreme authority. On any conflict, follow
the precedence in [engine.md](engine.md#3-resolution-order-precedence).

---

## 1. Brand integrity (non-negotiable)

- **Never change the company name.** `{{company_name}}` resolves from `../Brand/company.json` only.
- **Never translate** brand name, `{{tagline}}`, product/service proper names, or URLs.
- **Always use Brand colors** (`{{primary_color}}`, `{{secondary_color}}`) for any visual prompt.
- **Always use Brand fonts** (`{{heading_font}}`, `{{body_font}}`) for any design output.
- **Never invent services.** Only values present in `../Brand/services.json` are valid `{{service}}`.
- **Never reference or imitate competitors** or their branding.

## 2. Voice & tone

- **Always follow Brand tone.** Select the correct `tone.json` context via `{{tone_context}}`.
- Use `{{preferred_terms}}`; avoid everything in `{{avoid_terms}}`.
- Match tone to channel: social = `socialTone`, blog/website = `writingTone`, video = `videoTone`,
  email = `emailTone`, SEO = `seoTone`.

## 3. Content rules (from `../Brand/content-rules.json`)

- **Always include a Brand-approved CTA** (`{{cta}}` from `../Brand/cta.json`). One clear CTA per asset.
- No fake promises, no guarantees of rankings/revenue/results.
- No clickbait, no fake urgency, no fabricated stats/testimonials/clients.
- Short paragraphs (1–3 sentences), active voice, clear professional language.
- Fact-check any claim or number; if unverifiable, remove it or flag it.

## 4. Platform awareness

- **Always generate platform-specific content.** Respect `{{posting_spec}}`, `{{aspect_ratio}}`,
  length limits, `{{hashtag_policy}}` and `{{emoji_policy}}` for `{{platform}}`.
- A LinkedIn post ≠ an Instagram caption ≠ a YouTube script. Never reuse one format for another.

## 5. Language rules

- Output in `{{language}}` (`en`, `hi`, `gu`) with natural, native-level phrasing.
- Keep brand terms, URLs and code untranslated.
- Match cultural tone while preserving Brand voice.

## 6. SEO rules (when applicable)

- Follow `../Brand/seo.json` for titles, meta, slugs, headings and internal linking.
- Use `{{keywords}}` naturally — **never keyword-stuff**.
- One H1, logical heading hierarchy, descriptive anchors.

## 7. Video / audio rules (when applicable)

- Follow `{{video_style}}`, `{{animation_style}}`, `{{voice_style}}`, `{{subtitle_rules}}` from
  `../Brand/video-style.json`.
- Reels/Shorts follow the Hook → Value → Proof → CTA structure.

## 8. Prompt authoring rules (for template authors)

- Reference Brand data by variable — **never paste Brand values into a template.**
- Every template must include all nine standard sections (see engine.md).
- Every template must declare its Required and Optional variables.
- Every required variable must be validated before assembly.
- Every template must be platform-aware and language-aware.
- Include a **Validation Checklist** that blocks output on failure.

## 9. Safety & governance

- If required Brand data is missing/ambiguous → **stop and flag**, do not fabricate.
- All public-facing output requires human review (`ai-rules.json > humanReview`).
- Never expose placeholder contact/registration data as final.
- No discriminatory, political or offensive content.

## 10. Quick "must / must never"

**Must:** use Brand CTA · follow Brand tone · stay platform-specific · follow content rules · cite
only real services · keep brand terms intact.

**Must never:** change company name · invent services · make fake promises · use clickbait · use
competitor branding · translate brand terms · keyword-stuff · guess missing brand data.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
