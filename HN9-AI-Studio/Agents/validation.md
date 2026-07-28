# Validation Pipeline

Validation runs at every agent's **egress** and again, comprehensively, at the **QA Agent**. A
deliverable cannot reach Publisher until all applicable gates pass. Rules inherit from
`../Brand/content-rules.json`, `../Brand/ai-rules.json`, `../Brand/seo.json` and
`../Brand/video-style.json` — they are referenced, never duplicated.

## Validation pipeline (order)

```
Contract Validation → Brand Validation → Content Validation → Grammar Validation
→ Platform Validation → SEO Validation → Video/Media Validation → Prompt Validation
→ Final QA Gate → Human Approval
```

Each gate returns `{ rule, passed, details }`. Any `passed=false` blocks handoff.

## Brand Validation
- Company name exactly matches `../Brand/company.json`; never altered/translated.
- Only real services from `../Brand/services.json` are referenced.
- Colors/fonts match `../Brand/colors.json` / `fonts.json` (for visual prompts).
- Tone matches the correct `../Brand/tone.json` context.
- Contains a Brand-approved CTA from `../Brand/cta.json`.
- No competitor branding.

## Grammar Validation
- Native-level grammar and spelling in `{{language}}`.
- Short paragraphs (1–3 sentences), active voice.
- No banned vocabulary (`../Brand/tone.json > avoidTerms`).

## SEO Validation
- Applies when `needsSEO=true`. Follows `../Brand/seo.json`.
- Meta title ≤60 chars w/ brand; meta description 120–160 chars w/ CTA.
- Single H1, logical heading hierarchy, slug rules, internal-link rules.
- Keywords used naturally — **no stuffing**.

## Platform Validation
- Length, format and aspect ratio match `../Brand/social-media.json` / `video-style.json`.
- Hashtag count/style within `hashtags` policy; emoji within `emojiRules`.
- Content shaped for the specific `{{platform}}` (no cross-posting mismatch).

## Content Validation
- No fake promises, guarantees, clickbait or fake urgency.
- No fabricated stats/testimonials/clients.
- Exactly one primary CTA in the final user-facing asset.
- Claims are verifiable or removed/flagged.

## Video / Media Validation
- Reel structure Hook → Value → Proof → CTA present.
- Aspect ratio, subtitle rules, voice rules per `../Brand/video-style.json`.
- Duration within target; branded end card present.

## Prompt Validation (image/video/voice prompts)
- Brand palette only; on-brand style per `../Brand/design-system.json`.
- Correct aspect ratio; negative prompt present.
- In-asset text in `{{language}}`; brand names untranslated.

## Failure handling
- Any gate failing → route to `error-handling.md` (fix, retry, or escalate).
- Repeated brand/content failures escalate to human review (never auto-published).

**Version:** 1.0.0 · **Last updated:** 2026-07-18
