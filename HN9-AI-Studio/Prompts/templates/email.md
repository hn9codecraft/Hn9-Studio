# Template — Cold Email

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a concise, personalized cold outreach email that earns a reply, following
Subject → Body → CTA with `emailTone`.

## 2. Inputs
- Recipient/company context, goal, service to pitch, language.

## 3. Required Variables
`{{platform}}` (=`email`), `{{language}}`, `{{recipient}}`, `{{goal}}`, `{{service}}`,
`{{company_name}}`, `{{tone}}` (→ `emailTone`), `{{cta}}`, `{{cta_url}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{offer}}`, `{{audience}}`, `{{key_points}}`.

## 5. Prompt Template
```
Write a cold email in {{language}} from {{company_name}} to {{recipient}}.
Goal: {{goal}}. Service to pitch: {{service}}. Voice: {{tone}} (personal, respectful, direct).
Obey: {{brand_rules}}. No spam words, no fake urgency, no false claims.

Deliver:
- Subject line (short, specific, no spammy words).
- 1-line personalized opener (relevant to {{recipient}}).
- 2-3 short sentences on the value of {{service}} for their situation.
- One clear CTA: {{cta}} -> {{cta_url}}.
- Simple signature placeholder.

Keep it under ~120 words. One idea, one CTA. Keep brand terms untranslated.
```

## 6. Output Structure
```
subject, opener, body, cta, cta_url, signature
```

## 7. Validation Checklist
- [ ] Subject specific, non-spammy.
- [ ] Personalized opener; single value idea.
- [ ] One approved `{{cta}}`; correct language.
- [ ] Real service; no false claims; ~120 words.

## 8. Example Input
```
platform: email | language: en | recipient: "Founder of a 20-person SaaS"
goal: demo | service: AI Agents
```

## 9. Example Output
```
Subject: "Cutting your support load with an AI agent"
Opener: "Saw you're scaling support fast — quick idea."
Body: "We build AI agents that resolve routine tickets 24/7..." 
CTA: {{cta}} -> {{cta_url}}
```
