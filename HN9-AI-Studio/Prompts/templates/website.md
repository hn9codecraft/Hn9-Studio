# Template — Website Content

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate on-brand website copy for a page or section (home, about, service, contact) that is clear,
premium and conversion-focused.

## 2. Inputs
- Page/section, goal, audience, language. Optional: services to feature, key points.

## 3. Required Variables
`{{platform}}` (=`website`), `{{language}}`, `{{topic}}` (page/section), `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{tagline}}`, `{{service}}`, `{{tone}}` (→ `writingTone`), `{{cta}}`,
`{{seo_rules}}`, `{{design_system}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{key_points}}`, `{{keywords}}`.

## 5. Prompt Template
```
Write website copy in {{language}} for {{company_name}} ({{tagline}}).
Page/section: "{{topic}}". Goal: {{goal}}. Audience: {{audience}}. Voice: {{tone}}.
Reference only real services from the catalog (e.g. {{service}}). Obey: {{brand_rules}} and {{seo_rules}}.

Deliver copy blocks:
- Section heading (benefit-led) + supporting subheading.
- Body copy in short, scannable paragraphs.
- Bullet points for benefits/features where useful.
- Primary CTA {{cta}} (+ optional secondary CTA).
- Microcopy for buttons per {{design_system}} conventions.

Keep it premium, simple and trustworthy. Keep brand terms untranslated.
```

## 6. Output Structure
```
sections[{heading, subheading, body, bullets[], primaryCta, secondaryCta}]
```

## 7. Validation Checklist
- [ ] Premium, clear, benefit-led copy.
- [ ] Only real services referenced.
- [ ] Primary `{{cta}}` present; correct language.
- [ ] Headings follow SEO rules; no fake claims.

## 8. Example Input
```
platform: website | language: en | topic: "Home hero" | goal: lead
audience: Mid-size Companies | service: multiple
```

## 9. Example Output
```
Heading: "All your digital needs, under one roof"
Subheading: "Software, design, marketing and AI automation — from one trusted team."
CTA: {{cta}} (primary) + "See our work" (secondary)
```
