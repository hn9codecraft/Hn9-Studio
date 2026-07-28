# Template — SEO Article / SEO Metadata

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate search-optimized content or metadata (meta title, description, OG, schema, slug, headings)
strictly following `../../Brand/seo.json`.

## 2. Inputs
- Page type (home/service/blog), target keyword, topic, language.

## 3. Required Variables
`{{platform}}` (=`seo`), `{{language}}`, `{{topic}}`, `{{keywords}}`, `{{keywords_focus}}`,
`{{company_name}}`, `{{service}}`, `{{tone}}` (→ `seoTone`), `{{seo_rules}}`, `{{cta}}`,
`{{brand_rules}}`.

## 4. Optional Variables
`{{service_slug}}`, `{{word_count}}`, `{{audience}}`.

## 5. Prompt Template
```
Act as an SEO specialist for {{company_name}}. Language: {{language}}. Voice: {{tone}}.
Target keyword: {{keywords_focus}} (support keywords: {{keywords}}). Topic: "{{topic}}".
Follow strictly: {{seo_rules}}. Obey: {{brand_rules}}.

Produce:
1. Meta title (use the correct template, <=60 chars, includes brand).
2. Meta description (120-160 chars, includes keyword + {{cta}}).
3. URL slug (lowercase, hyphenated, no stop words).
4. H1 + H2/H3 outline with keyword placement.
5. Open Graph + schema.org JSON stub (Organization/Service/Article as appropriate).
6. Internal-linking suggestions.

Use keywords naturally. Never keyword-stuff. Keep brand terms untranslated.
```

## 6. Output Structure
```
metaTitle, metaDescription, slug, headings[], openGraph{}, schema{}, internalLinks[]
```

## 7. Validation Checklist
- [ ] Title ≤60 chars w/ brand; description 120–160 chars w/ CTA.
- [ ] Slug follows `seo.json > slugRules`.
- [ ] One H1, keyword in H1 + one H2.
- [ ] Valid schema stub; no keyword stuffing.

## 8. Example Input
```
platform: seo | language: en | pageType: service | keywords_focus: "custom crm development"
service: CRM Development | service_slug: crm-development
```

## 9. Example Output
```
Meta Title: "CRM Development Company | <brand>"
Meta Description: "Need custom CRM development? ... Book your free consultation today."
Slug: crm-development | Schema: {"@type":"Service", ...}
```
