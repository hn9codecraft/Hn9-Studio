# Template — Carousel (Instagram / LinkedIn)

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a multi-slide carousel (hook slide → value slides → CTA slide) with per-slide copy and
design direction.

## 2. Inputs
- Topic, platform, slide count, goal, audience, language.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{goal}}`, `{{audience}}`, `{{company_name}}`,
`{{service}}`, `{{tone}}`, `{{cta}}`, `{{primary_color}}`, `{{secondary_color}}`,
`{{heading_font}}`, `{{body_font}}`, `{{design_system}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{slide_count}}` (default 6), `{{key_points}}`, `{{hashtag_policy}}`.

## 5. Prompt Template
```
Create a {{slide_count}}-slide carousel in {{language}} for {{platform}}, by {{company_name}}.
Topic: "{{topic}}". Audience: {{audience}}. Goal: {{goal}}. Voice: {{tone}}.
Design: brand colors {{primary_color}}/{{secondary_color}}, fonts {{heading_font}}/{{body_font}},
per {{design_system}}. Obey: {{brand_rules}}. Reference real service {{service}}.

Per slide provide:
- Slide 1: bold hook.
- Middle slides: one idea each (headline + 1-2 supporting lines).
- Last slide: {{cta}}.
Add a short caption + hashtags per {{hashtag_policy}} (if Instagram).

Keep text minimal and scannable. Keep brand terms untranslated.
```

## 6. Output Structure
```
slides[{number, headline, body}], caption, hashtags[], cta, designNotes
```

## 7. Validation Checklist
- [ ] Slide 1 hooks; one idea per middle slide.
- [ ] Final slide has approved `{{cta}}`.
- [ ] Brand colors/fonts specified; real service.
- [ ] Correct language; brand terms intact.

## 8. Example Input
```
platform: linkedin | language: en | topic: "5 signs you need AI automation" | slide_count: 7
audience: Growth Lead | service: AI Automation
```

## 9. Example Output
```
Slide 1: "5 signs you need AI automation"
Slides 2-6: one sign each
Slide 7: {{cta}}
```
