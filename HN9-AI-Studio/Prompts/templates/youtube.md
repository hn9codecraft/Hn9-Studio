# Template — YouTube (Long-form / Shorts)

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a YouTube video package (title, description, hook, script direction) following
Hook → Script → Scenes → CTA. Feeds `script.md` and `storyboard.md`.

## 2. Inputs
- Topic, goal, format (long/short), audience, language, duration.

## 3. Required Variables
`{{platform}}` (=`youtube`), `{{language}}`, `{{topic}}`, `{{goal}}`, `{{audience}}`,
`{{duration}}`, `{{company_name}}`, `{{service}}`, `{{tone}}` (→ `videoTone`), `{{cta}}`,
`{{video_style}}`, `{{seo_rules}}`, `{{keywords}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{aspect_ratio}}` (default `16:9` long / `9:16` shorts), `{{key_points}}`.

## 5. Prompt Template
```
Create a YouTube {{duration}} video package in {{language}} for {{company_name}}.
Topic: "{{topic}}". Audience: {{audience}}. Goal: {{goal}}. Service focus: {{service}}.
Voice: {{tone}}. Follow video style: {{video_style}}. Obey: {{brand_rules}}.

Deliver:
1. Title (SEO per {{seo_rules}}, uses a keyword from {{keywords}}).
2. Retention hook (first 3-5s).
3. Script direction: intro → key points → demo/proof → CTA.
4. Scene list for storyboard handoff.
5. Description (2-3 lines + {{cta}} + link) and 5-8 tags from {{keywords}}.

Aspect ratio {{aspect_ratio}}. End card: logo + tagline + {{cta}}. Keep brand terms untranslated.
```

## 6. Output Structure
```
title, hook, scriptOutline[], scenes[], description, tags[], cta, aspectRatio
```

## 7. Validation Checklist
- [ ] SEO title with a real keyword; single clear topic.
- [ ] Retention hook in first 5s.
- [ ] Ends with subscribe + primary `{{cta}}`.
- [ ] Correct aspect ratio & language; brand terms intact.

## 8. Example Input
```
platform: youtube | language: en | topic: "3 processes every business should automate with AI"
goal: awareness | audience: Growth Lead | duration: 6min | service: AI Automation
```

## 9. Example Output
```
Title: "3 Processes to Automate with AI (Save 10+ Hours/Week)"
Hook: "If your team still does this by hand, you're losing hours every week."
Outline: Intro → Process 1/2/3 → mini demo → CTA
Description: "..." + {{cta}} + link | Tags: ai automation, workflow automation, ...
```
