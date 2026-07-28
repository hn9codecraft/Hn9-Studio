# Template — Image Prompt

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a detailed, on-brand prompt for AI image generation (social visuals, backgrounds,
illustrations, mockups) for downstream image models.

## 2. Inputs
- Concept/subject, use case, aspect ratio, style reference, language (for any in-image text).

## 3. Required Variables
`{{platform}}`, `{{language}}`, `{{topic}}` (concept), `{{aspect_ratio}}`, `{{company_name}}`,
`{{primary_color}}`, `{{secondary_color}}`, `{{design_system}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{style_reference}}`, `{{service}}`, `{{gradient}}`.

## 5. Prompt Template
```
Write an image-generation prompt (in English for the model) for {{company_name}}.
Concept: "{{topic}}". Use case/platform: {{platform}}. Aspect ratio: {{aspect_ratio}}.
Illustration/style per {{design_system}} (modern, minimal, geometric). Brand palette: {{primary_color}},
{{secondary_color}} (+ {{gradient}} if relevant). Obey: {{brand_rules}}.

Specify:
- Subject & composition.
- Style (flat, subtle depth, rounded shapes) and mood (premium, clean).
- Color usage (brand palette only).
- Any in-image text in {{language}} (kept minimal, brand names untranslated).
- Negative prompt: off-brand colors, clutter, realistic photo (unless requested), distorted text.
```

## 6. Output Structure
```
imagePrompt, style, palette[], aspectRatio, negativePrompt
```

## 7. Validation Checklist
- [ ] Brand palette only; matches `design-system.json` style.
- [ ] Aspect ratio correct; premium/clean mood.
- [ ] In-image text in `{{language}}`, brand names intact.
- [ ] Negative prompt included.

## 8. Example Input
```
platform: instagram | language: en | topic: "AI agents helping a support team" | aspect_ratio: 4:5
```

## 9. Example Output
```
imagePrompt: "Minimal geometric illustration, friendly AI agent + support desk, deep-blue base,
amber accents, rounded shapes, soft depth, premium, 4:5"
negativePrompt: "photo-real, clutter, off-brand colors, distorted text"
```
