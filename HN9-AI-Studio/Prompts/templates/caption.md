# Template — Caption

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a platform-specific social caption (hook + value + CTA) to accompany a post, reel or video.

## 2. Inputs
- Post topic/summary, platform, goal, audience, language.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{goal}}`, `{{audience}}`, `{{company_name}}`,
`{{service}}`, `{{tone}}` (→ `socialTone`), `{{cta}}`, `{{emoji_policy}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{key_points}}`, `{{hashtag_policy}}` (if hashtags requested inline).

## 5. Prompt Template
```
Write a {{platform}} caption in {{language}} for {{company_name}}.
Post topic: "{{topic}}". Audience: {{audience}}. Goal: {{goal}}. Voice: {{tone}}.
Obey: {{brand_rules}}. Emojis per {{emoji_policy}} (platform-appropriate).

Structure:
- Hook line (stop the scroll).
- 1-2 value lines (benefit, tied to {{service}} when relevant).
- CTA: {{cta}}.

Match caption length + style to {{platform}}. Keep brand terms untranslated. No clickbait.
```

## 6. Output Structure
```
hook, body, cta, (optional) inlineHashtags[]
```

## 7. Validation Checklist
- [ ] Hook first; concise, platform-appropriate length.
- [ ] Real service (if referenced); approved `{{cta}}`.
- [ ] Emoji policy respected; correct language.
- [ ] No clickbait; brand terms intact.

## 8. Example Input
```
platform: instagram | language: en | topic: "New Shopify store launch tips" | goal: engagement
audience: Small Business Owner | service: Shopify Development
```

## 9. Example Output
```
Hook: "Launching a store? Don't skip this 👇"
Body: "A fast, clean Shopify build sells while you sleep."
CTA: {{cta}}
```
