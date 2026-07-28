# Template — Reel / Short (generic short-form)

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a channel-agnostic short-form vertical video concept (Instagram Reels / YouTube Shorts /
Facebook Reels) using the Hook → Value → Proof → CTA structure.

## 2. Inputs
- Topic, target platform, duration, goal, audience, language.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{duration}}`, `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{service}}`, `{{tone}}` (→ `videoTone`), `{{cta}}`, `{{video_style}}`,
`{{subtitle_rules}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{aspect_ratio}}` (default `9:16`), `{{music_style}}`, `{{key_points}}`.

## 5. Prompt Template
```
Create a {{duration}} vertical reel in {{language}} for {{platform}} by {{company_name}}.
Topic: "{{topic}}". Audience: {{audience}}. Goal: {{goal}}. Service: {{service}}.
Voice: {{tone}}. Follow: {{video_style}} (Hook->Value->Proof->CTA), subtitles per {{subtitle_rules}},
music mood {{music_style}}. Obey: {{brand_rules}}.

Output:
- Hook (0-3s) on-screen + spoken.
- Value beats (spoken + on-screen text).
- Proof beat (optional, honest).
- CTA beat: {{cta}}.
- Suggested captions/subtitles timing notes.

Aspect ratio {{aspect_ratio}}. Keep brand terms untranslated. No fake promises.
```

## 6. Output Structure
```
hook, beats[{type, onScreen, spoken}], cta, aspectRatio, musicMood
```

## 7. Validation Checklist
- [ ] Hook in first 3s; fits `{{duration}}`.
- [ ] 9:16; subtitle rules noted.
- [ ] Real service; ends with `{{cta}}`.
- [ ] Correct language; brand terms intact.

## 8. Example Input
```
platform: youtube | language: gu | topic: "App vs website — what to build first" | duration: 45s
audience: Startup Founder | service: Mobile App Development
```

## 9. Example Output
```
Hook: "પહેલા App બનાવવી કે Website?"
Value beats → Proof → CTA: {{cta}}
```
