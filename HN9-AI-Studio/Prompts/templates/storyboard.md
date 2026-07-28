# Template — Storyboard

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Turn a script into a scene-by-scene visual plan (shots, on-screen text, b-roll, transitions) for
video production.

## 2. Inputs
- The script (or topic), platform, scene count, aspect ratio, language.

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}`, `{{scene_count}}`, `{{aspect_ratio}}`,
`{{company_name}}`, `{{video_style}}`, `{{animation_style}}`, `{{subtitle_rules}}`,
`{{primary_color}}`, `{{secondary_color}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{style_reference}}`, `{{service}}`.

## 5. Prompt Template
```
Create a storyboard in {{language}} for a {{platform}} video by {{company_name}}.
Topic/script: "{{topic}}". Scenes: {{scene_count}}. Aspect ratio: {{aspect_ratio}}.
Follow visual style: {{video_style}} and {{animation_style}}. Subtitles per {{subtitle_rules}}.
Use brand colors {{primary_color}} / {{secondary_color}}. Obey: {{brand_rules}}.

For each scene provide:
- Scene number & duration.
- Shot description (framing, subject, movement).
- On-screen text (short).
- B-roll / visual asset suggestion.
- Transition to next scene.

End with a branded end card (logo + tagline + CTA). Keep brand terms untranslated.
```

## 6. Output Structure
```
scenes[{number, duration, shot, onScreenText, broll, transition}], endCard
```

## 7. Validation Checklist
- [ ] Scene count & aspect ratio respected.
- [ ] Brand colors and style applied.
- [ ] Subtitle rules followed; branded end card.
- [ ] Correct language; brand terms intact.

## 8. Example Input
```
platform: youtube | language: en | topic: "3 AI processes to automate" | scene_count: 5
aspect_ratio: 16:9
```

## 9. Example Output
```
Scene 1 (0-5s): Close-up host, text "Stop doing this by hand", cut
Scene 2-4: process demos with screen recordings + accent-color callouts
Scene 5: End card — logo + tagline + {{cta}}
```
