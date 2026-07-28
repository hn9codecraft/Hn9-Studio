# Template — Client Proposal

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a structured, professional project proposal that restates the client's problem, maps real
services to a solution, and drives to a demo/kickoff.

## 2. Inputs
- Client name/context, project scope, services involved, goal, language.

## 3. Required Variables
`{{platform}}` (=`proposal`), `{{language}}`, `{{recipient}}` (client), `{{topic}}` (project),
`{{service}}` (one or more), `{{company_name}}`, `{{tagline}}`, `{{tone}}` (→ `writingTone`),
`{{cta}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{key_points}}`, `{{audience}}`, `{{offer}}`.

## 5. Prompt Template
```
Write a client proposal in {{language}} from {{company_name}} ({{tagline}}) for {{recipient}}.
Project: "{{topic}}". Services proposed (only real ones): {{service}}. Voice: {{tone}}.
Obey: {{brand_rules}}. Do not fabricate timelines, prices, or past clients — use placeholders.

Sections:
1. Executive summary.
2. Understanding of the client's problem/goal.
3. Proposed solution mapped to {{service}}.
4. Approach & process (phases).
5. Why {{company_name}} (differentiators, honest).
6. Scope & deliverables (placeholders where specifics are unknown).
7. Timeline & investment (marked as placeholders).
8. Next step: {{cta}}.

Professional and premium. Keep brand terms untranslated.
```

## 6. Output Structure
```
executiveSummary, understanding, solution, approach[], whyUs, scope[], timeline, investment, cta
```

## 7. Validation Checklist
- [ ] Restates client problem accurately.
- [ ] Maps only real services; honest differentiators.
- [ ] Prices/timelines marked as placeholders (not invented).
- [ ] Ends with approved `{{cta}}`; correct language.

## 8. Example Input
```
platform: proposal | language: en | recipient: "RetailCo" | topic: "Ecommerce replatform"
service: "Shopify Development, UI/UX Design, SEO" | goal: partnership
```

## 9. Example Output
```
Executive Summary → Understanding → Solution (Shopify + UI/UX + SEO) → Approach
→ Why us → Scope [placeholders] → Timeline [placeholder] → {{cta}}
```
