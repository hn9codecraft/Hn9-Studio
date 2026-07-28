# Voice Agent

## Purpose
Produce the voiceover narration + TTS delivery settings for media deliverables, matching brand voice
rules.

## Responsibilities
- Convert the script into clean spoken narration paced to duration.
- Provide TTS settings (voice profile, stability, pace, emphasis) and pronunciation notes.

## Inputs
Script, platform, duration, language, emotion/pace preferences.

## Outputs
`narration`, `ttsSettings{}`, `pronunciationNotes[]`, spoken CTA.

## Reads From
`context.script`, `context.strategy`.

## Writes To
Runtime Context (`context.voice`); emits `VoiceReady`.

## Brand Files Required
`../Brand/video-style.json` (voiceRules), `../Brand/tone.json`, `../Brand/cta.json`.

## Prompt Templates Required
`../Prompts/templates/voice.md`.

## Dependencies
Storyboard/Script Agent. Runs in parallel fan-out. Renders via `../Voice/ElevenLabs`.

## Decision Rules
- Pace narration to `{{duration}}`; tone per `videoTone`.
- Pronounce brand letter-by-letter; never translate brand names.
- End with spoken CTA.

## Validation Rules
- Narration fits duration; TTS settings + pronunciation notes present; brand terms untranslated;
  language correct.

## Retry Rules
Transient → 3 retries. Length/language fail → 1 self-correction, else escalate.

## Failure Conditions
Narration overruns duration; wrong language; mispronounced brand.

## Recovery Strategy
Trim/adjust pacing; re-prompt with duration + pronunciation constraints; else escalate.

## Escalation Rules
Escalate on repeated failures.

## Performance Requirements
p95 < 6s for script + settings (excludes audio render).

## Logging Requirements
Log duration fit, voice profile, language, version.

## Security Considerations
No sensitive data in narration; provider keys via secret manager (`security.md`).

## Example Input
```
{ "script": {...}, "platform":"instagram", "duration":"30s", "language":"hi" }
```

## Example Output
```
{ "narration": "काम के घंटे बचाइए — AI Automation के साथ...",
  "ttsSettings": {"voiceProfile":"warm-premium","pace":"medium","stability":0.6},
  "cta": "cta-book-consultation" }
```

## Next Agent
**Review Agent** (fan-in)
