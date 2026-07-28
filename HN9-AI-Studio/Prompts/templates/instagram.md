# Template — Instagram Reel

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a short-form Instagram Reel concept + caption that hooks fast, delivers one clear value,
and closes with a Brand-approved CTA. Pairs with `reel.md`, `script.md`, `voice.md`, `hashtags.md`.

## 2. Inputs
- The topic and the goal of the reel.
- Target audience segment and output language.
- Optional: duration, offer, key points.

## 3. Required Variables
`{{platform}}` (=`instagram`), `{{language}}`, `{{topic}}`, `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{tagline}}`, `{{service}}`, `{{tone}}` (→ `socialTone`), `{{cta}}`,
`{{brand_rules}}`, `{{hashtag_policy}}`, `{{emoji_policy}}`.

## 4. Optional Variables
`{{duration}}` (default `30s`), `{{offer}}`, `{{key_points}}`, `{{aspect_ratio}}` (default `9:16`).

## 5. Prompt Template
```
You are the Instagram content creator for {{company_name}} ({{tagline}}).
Write a {{duration}} Instagram Reel in {{language}} about "{{topic}}" for {{audience}}.
Goal: {{goal}}. Map the solution to our real service: {{service}}.

Follow the brand social voice: {{tone}}.
Obey these rules: {{brand_rules}}.
Structure the reel as: Hook (0-3s) → Problem → Solution → Benefits → CTA.
End with this exact CTA: {{cta}}.

Deliver:
1. On-screen text for each section (punchy, <=8 words per line).
2. A spoken voiceover line per section.
3. A caption (hook line + 1-2 value lines + {{cta}}) following {{emoji_policy}}.
4. Hashtags per {{hashtag_policy}}.

Constraints: aspect ratio {{aspect_ratio}}; no fake promises; keep brand terms untranslated.
```

## 6. Output Structure
```
title, hook, sections[{name, onScreenText, voiceover}], caption, hashtags[], cta, aspectRatio
```

## 7. Validation Checklist
- [ ] Hook lands in first 3 seconds.
- [ ] Maps to a real service from `services.json`.
- [ ] Ends with an approved `{{cta}}`.
- [ ] Caption follows emoji policy; hashtags follow `{{hashtag_policy}}`.
- [ ] 9:16, correct language, brand terms untranslated.
- [ ] No fake promises / clickbait.

## 8. Example Input
```
platform: instagram | language: en | topic: "Automating lead follow-ups with AI"
goal: lead | audience: Small Business Owner | service: AI Automation | duration: 30s
```

## 9. Example Output
```
Hook: "Still chasing leads by hand?"
Problem: "Manual follow-ups cost you sales every week."
Solution: "We set up AI that replies in seconds — that's {{service}}."
Benefits: "More booked calls. Zero busywork."
CTA: {{cta}} (e.g. "DM us to book a free consultation")
Caption: "Your leads won't wait ⏳ Automate follow-ups and win more deals. {{cta}}"
Hashtags: #AIAutomation #SmallBusiness #Leads ... (per policy)
```
