# Template — Facebook Post

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a friendly, value-first Facebook post that drives engagement or leads for a local/SMB
audience.

## 2. Inputs
- Topic, goal, audience, language. Optional: offer, link, key points.

## 3. Required Variables
`{{platform}}` (=`facebook`), `{{language}}`, `{{topic}}`, `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{service}}`, `{{tone}}` (→ `socialTone`), `{{cta}}`, `{{cta_url}}`,
`{{brand_rules}}`, `{{hashtag_policy}}`, `{{emoji_policy}}`.

## 4. Optional Variables
`{{offer}}`, `{{key_points}}`.

## 5. Prompt Template
```
Write a Facebook post in {{language}} for {{company_name}} targeting {{audience}}.
Voice: {{tone}} (friendly, approachable, professional). Topic: "{{topic}}". Goal: {{goal}}.
Relevant service: {{service}}. Obey: {{brand_rules}}.

Structure: friendly hook → clear value in 2-4 short lines → {{cta}} with link {{cta_url}}.
Use tasteful emojis per {{emoji_policy}} and 2-4 hashtags per {{hashtag_policy}}.
Keep brand terms untranslated. No clickbait or fake urgency.
```

## 6. Output Structure
```
hook, body, cta, cta_url, hashtags[]
```

## 7. Validation Checklist
- [ ] Friendly, on-brand tone; value in first 2 lines.
- [ ] Approved `{{cta}}` + valid link.
- [ ] Emoji/hashtag policy respected; correct language.
- [ ] Real service only; no clickbait.

## 8. Example Input
```
platform: facebook | language: en | topic: "Get a website that actually brings customers"
goal: lead | audience: Small Business Owner | service: Website Development
```

## 9. Example Output
```
Hook: "Is your website working as hard as you are? 💼"
Body: "A fast, modern site turns visitors into customers — no tech headaches for you."
CTA: {{cta}} → {{cta_url}}
Hashtags: #WebDevelopment #SmallBusiness
```
