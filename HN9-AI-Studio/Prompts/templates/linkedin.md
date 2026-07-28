# Template — LinkedIn Post

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a professional LinkedIn post that builds authority and trust, following the Hook → Story →
Value → CTA workflow.

## 2. Inputs
- Topic/angle, goal, audience segment, language.
- Optional: a real example or lesson, key points.

## 3. Required Variables
`{{platform}}` (=`linkedin`), `{{language}}`, `{{topic}}`, `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{service}}`, `{{tone}}` (→ `writingTone`), `{{cta}}`, `{{brand_rules}}`,
`{{hashtag_policy}}`.

## 4. Optional Variables
`{{key_points}}`, `{{offer}}`, `{{word_count}}` (default 120–200 words).

## 5. Prompt Template
```
You are writing a LinkedIn post for {{company_name}}, aimed at {{audience}}.
Write in {{language}} with a professional, modern voice: {{tone}}.
Topic: "{{topic}}". Goal: {{goal}}. Relevant service: {{service}}.
Obey: {{brand_rules}}.

Structure:
- Hook: one scroll-stopping opening line.
- Story: a short, relatable context or mini case (no fabricated clients).
- Value: a concrete insight, lesson, or how-to (short paragraphs).
- CTA: end with {{cta}}.

Add 3-5 professional hashtags per {{hashtag_policy}}. Minimal emojis. Keep brand terms untranslated.
```

## 6. Output Structure
```
hook, body[story, value], cta, hashtags[]
```

## 7. Validation Checklist
- [ ] Strong first-line hook.
- [ ] Professional tone (writingTone), short paragraphs.
- [ ] Real service only; no fabricated clients/stats.
- [ ] Ends with approved `{{cta}}`.
- [ ] 3–5 hashtags; minimal emojis; correct language.

## 8. Example Input
```
platform: linkedin | language: en | topic: "Why one-vendor delivery beats five freelancers"
goal: awareness | audience: Growth Lead | service: Website Development
```

## 9. Example Output
```
Hook: "Five freelancers. Three time zones. One missed deadline."
Story: "We hear this every week from teams stitching vendors together."
Value: "Under one roof, design, dev and SEO move as one — fewer handoffs, faster launches."
CTA: {{cta}} (e.g. "Contact us today")
Hashtags: #DigitalTransformation #WebDevelopment #Startups
```
