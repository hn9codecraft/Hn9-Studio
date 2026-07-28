# Template — Voice / Voiceover Prompt

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a voiceover script + TTS delivery settings (for ElevenLabs / TTS) matching the brand voice
rules. Feeds `../../Voice/ElevenLabs`.

## 2. Inputs
- Script or topic, platform, duration, language, emotion/pace.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}` (or script), `{{duration}}`, `{{company_name}}`,
`{{voice_style}}`, `{{tone}}` (→ `videoTone`/`speakingTone`), `{{cta}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{key_points}}`, `{{service}}`.

## 5. Prompt Template
```
Produce a voiceover in {{language}} for {{company_name}} for a {{platform}} asset ({{duration}}).
Topic/script: "{{topic}}". Voice guidance: {{voice_style}} and tone {{tone}} (confident, warm, premium).
Obey: {{brand_rules}}.

Deliver:
1. Clean narration text (spoken only), paced for {{duration}}.
2. TTS settings: suggested voice profile, stability, pace, emphasis words.
3. Pronunciation notes (e.g. pronounce the brand letter-by-letter; never translate brand names).
4. End line with spoken {{cta}}.

Natural, human, not rushed. Keep brand terms untranslated.
```

## 6. Output Structure
```
narration, ttsSettings{voiceProfile, stability, pace, emphasis[]}, pronunciationNotes[], cta
```

## 7. Validation Checklist
- [ ] Narration fits `{{duration}}`; natural pacing.
- [ ] TTS settings + pronunciation notes included.
- [ ] Brand pronounced correctly; brand terms untranslated.
- [ ] Ends with spoken `{{cta}}`; correct language.

## 8. Example Input
```
platform: instagram | language: hi | topic: "AI automation reel VO" | duration: 30s
service: AI Automation
```

## 9. Example Output
```
Narration: "काम के घंटे बचाइए — AI Automation के साथ..." 
ttsSettings: { voiceProfile: "warm-premium", pace: "medium", stability: 0.6 }
CTA (spoken): {{cta}}
```
