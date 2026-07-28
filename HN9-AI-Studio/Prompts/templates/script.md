# Template — Video Script

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a spoken script for a reel, short, or long-form video, timed and structured, ready for
`voice.md` and `storyboard.md`.

## 2. Inputs
- Topic, platform, duration, goal, audience, language.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{duration}}`, `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{service}}`, `{{tone}}` (→ `videoTone`), `{{cta}}`, `{{video_style}}`,
`{{brand_rules}}`.

## 4. Optional Variables
`{{key_points}}`, `{{voice_style}}`.

## 5. Prompt Template
```
Write a {{duration}} video script in {{language}} for {{platform}}, for {{company_name}}.
Topic: "{{topic}}". Audience: {{audience}}. Goal: {{goal}}. Service focus: {{service}}.
Voice: {{tone}}. Follow: {{video_style}}. Obey: {{brand_rules}}.

Structure with timecodes:
[0:00] Hook — grab attention in one line.
[..]   Problem — the audience pain.
[..]   Solution — {{service}} in plain language.
[..]   Benefits/Proof — outcomes (honest).
[end]  CTA — {{cta}}.

Output spoken lines only (no stage direction), one line per beat, natural for {{voice_style}}.
Keep brand terms untranslated. No fake promises.
```

## 6. Output Structure
```
scenes[{timecode, spokenLine}], totalDuration, cta
```

## 7. Validation Checklist
- [ ] Hook in first line; fits `{{duration}}`.
- [ ] Real service; honest benefits.
- [ ] Ends with `{{cta}}`; natural spoken language.
- [ ] Correct language; brand terms intact.

## 8. Example Input
```
platform: instagram | language: hi | topic: "Website that converts" | duration: 30s
audience: Small Business Owner | service: Website Development
```

## 9. Example Output
```
[0:00] "आपकी वेबसाइट ग्राहक ला रही है या सिर्फ दिख रही है?"
[0:05] "धीमी, पुरानी साइट ग्राहक खो देती है।"
[0:12] "हम बनाते हैं तेज़, मॉडर्न वेबसाइट — Website Development."
[0:24] {{cta}}
```
