# Template — Hashtags

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a platform-optimized hashtag set mixing brand, topic, tech and audience tags, following the
per-platform limits in `../../Brand/social-media.json`.

## 2. Inputs
- Post topic, platform, service, language (for localized topic tags where relevant).

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{service}}`, `{{hashtag_policy}}`, `{{keywords}}`,
`{{brand_rules}}`.

## 4. Optional Variables
`{{audience}}`, `{{keywords_focus}}`.

## 5. Prompt Template
```
Generate hashtags for a {{platform}} post about "{{topic}}" (service: {{service}}) for {{company_name}}.
Follow {{hashtag_policy}} strictly (count + style for {{platform}}). Draw from brand + core-topic +
tech + audience sets and {{keywords}}. Obey: {{brand_rules}}.

Rules:
- Respect the platform's max count (e.g. LinkedIn 3-5, Instagram 8-15, X 1-3).
- Mix: brand tags + topic tags + niche/tech tags + 1-2 audience tags.
- No banned/spammy/irrelevant tags. No overstuffing.
- Keep branded hashtags exactly as defined (do not translate).
```

## 6. Output Structure
```
hashtags[], count, platform
```

## 7. Validation Checklist
- [ ] Count within `{{platform}}` limit.
- [ ] Balanced mix (brand/topic/tech/audience).
- [ ] No banned/irrelevant tags; branded tags exact.
- [ ] Relevant to `{{topic}}` and `{{service}}`.

## 8. Example Input
```
platform: instagram | language: en | topic: "AI automation for SMBs" | service: AI Automation
```

## 9. Example Output
```
#HN9 #AIAutomation #WorkflowAutomation #SmallBusiness #DigitalTransformation
#AIAgents #Productivity #HN9Studio ... (8-15 per policy)
```
