# Template — Sales Email / Sales Copy

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate persuasive but honest sales copy (follow-up email, pitch, or offer message) for a warm
lead, driving toward a demo or consultation.

## 2. Inputs
- Recipient/context, service, goal, offer, language.

## 3. Required Variables
`{{platform}}` (=`sales`), `{{language}}`, `{{recipient}}`, `{{service}}`, `{{goal}}`,
`{{company_name}}`, `{{tone}}` (→ `emailTone`/`writingTone`), `{{cta}}`, `{{cta_url}}`,
`{{audience}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{offer}}`, `{{key_points}}`.

## 5. Prompt Template
```
Write persuasive sales copy in {{language}} from {{company_name}} for {{recipient}} ({{audience}}).
Service: {{service}}. Goal: {{goal}}. Offer (if any): {{offer}}. Voice: {{tone}}.
Obey: {{brand_rules}}. Persuade with real value only — no fake promises, guarantees, or pressure.

Structure:
- Hook tied to their goal/pain.
- Value: outcomes {{service}} delivers (specific, honest).
- Light proof: process/approach (no fabricated results).
- Clear next step: {{cta}} -> {{cta_url}}.

Confident, warm, premium. One CTA. Keep brand terms untranslated.
```

## 6. Output Structure
```
subject?, hook, value, proof, cta, cta_url
```

## 7. Validation Checklist
- [ ] Persuasive yet honest; no guarantees.
- [ ] Real service + outcomes; no fabricated proof.
- [ ] One approved `{{cta}}`; correct language.

## 8. Example Input
```
platform: sales | language: en | recipient: "Ops lead who requested a quote"
service: ERP Development | goal: demo | offer: "Free scoping call"
```

## 9. Example Output
```
Hook: "You asked how to unify finance, inventory and HR — here's the short version."
Value: "A custom ERP gives you one system and real-time reporting."
CTA: {{cta}} -> {{cta_url}}
```
