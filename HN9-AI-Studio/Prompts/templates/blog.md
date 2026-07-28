# Template — Blog Article

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a full SEO-friendly blog article following Title → Outline → Article → SEO → CTA.

## 2. Inputs
- Topic, target keyword, audience, language, word count.

## 3. Required Variables
`{{platform}}` (=`blog`), `{{language}}`, `{{topic}}`, `{{audience}}`, `{{keywords}}`,
`{{company_name}}`, `{{service}}`, `{{tone}}` (→ `writingTone`), `{{cta}}`, `{{seo_rules}}`,
`{{brand_rules}}`.

## 4. Optional Variables
`{{word_count}}` (default 1200–1600), `{{key_points}}`, `{{keywords_focus}}`.

## 5. Prompt Template
```
Write a {{word_count}}-word blog article in {{language}} for {{company_name}}.
Topic: "{{topic}}". Audience: {{audience}}. Primary keyword: {{keywords_focus}} (from {{keywords}}).
Voice: {{tone}}. Related service to reference naturally: {{service}}. Obey: {{brand_rules}} and {{seo_rules}}.

Structure:
1. SEO title (per {{seo_rules}}) and meta description.
2. Intro that states the reader's problem and promise.
3. Body with H2/H3 sections, short paragraphs, bullet lists where useful.
4. Natural keyword use — never stuff.
5. Internal-link suggestions (to service pages / related posts).
6. Conclusion + {{cta}}.

One H1, logical hierarchy. Keep brand terms untranslated. No fake claims.
```

## 6. Output Structure
```
seoTitle, metaDescription, slug, h1, sections[{h2, content, h3[]}], internalLinks[], conclusion, cta
```

## 7. Validation Checklist
- [ ] One H1; logical H2/H3 hierarchy.
- [ ] Keyword used naturally (no stuffing).
- [ ] Meta title ≤60 chars, meta description 120–160 chars.
- [ ] Internal links suggested; real service referenced.
- [ ] Ends with approved `{{cta}}`; correct language.

## 8. Example Input
```
platform: blog | language: en | topic: "How AI automation saves small businesses 10 hours a week"
audience: Small Business Owner | keywords_focus: "ai automation for small business"
service: AI Automation | word_count: 1400
```

## 9. Example Output
```
SEO Title: "AI Automation for Small Business: Save 10+ Hours a Week"
Meta: "Discover how AI automation helps small businesses cut busywork... Book a free consultation."
H1 + 5 H2 sections + conclusion + {{cta}} | Internal links: /services/ai-automation
```
