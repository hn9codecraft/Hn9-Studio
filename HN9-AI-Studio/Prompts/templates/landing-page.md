# Template — Landing Page

> Platform-aware · Languages: `en` · `hi` · `gu` · References Brand Brain (`../../Brand`) — no hardcoded values.

## 1. Purpose
Generate a high-converting, single-goal landing page for a specific service or campaign.

## 2. Inputs
- Service/offer, goal, audience, language. Optional: offer details, proof points.

## 3. Required Variables
`{{platform}}` (=`landing-page`), `{{language}}`, `{{service}}`, `{{goal}}`, `{{audience}}`,
`{{company_name}}`, `{{tagline}}`, `{{tone}}` (→ `writingTone`), `{{cta}}`, `{{cta_url}}`,
`{{seo_rules}}`, `{{design_system}}`, `{{brand_rules}}`.

## 4. Optional Variables
`{{offer}}`, `{{key_points}}`, `{{keywords}}`.

## 5. Prompt Template
```
Write a conversion-focused landing page in {{language}} for {{company_name}} promoting {{service}}.
Single goal: {{goal}}. Audience: {{audience}}. Voice: {{tone}}. Obey: {{brand_rules}} and {{seo_rules}}.

Sections:
1. Hero: headline (outcome), subhead, primary CTA {{cta}} -> {{cta_url}}.
2. Problem/agitation (from audience pain points).
3. Solution: how {{service}} solves it (benefits, not just features).
4. Proof: benefits / process / trust signals (no fabricated testimonials).
5. Objection handling / FAQ.
6. Final CTA block ({{cta}}).

One primary CTA repeated; scannable; premium tone. Keep brand terms untranslated.
```

## 6. Output Structure
```
hero{headline, subhead, cta}, problem, solution, proof, faq[], finalCta
```

## 7. Validation Checklist
- [ ] One clear goal and one primary CTA (repeated).
- [ ] Real service + honest benefits only.
- [ ] SEO-compliant headings; correct language.
- [ ] No fabricated proof or fake urgency.

## 8. Example Input
```
platform: landing-page | language: en | service: Shopify Development | goal: lead
audience: Small Business Owner | offer: "Free store audit"
```

## 9. Example Output
```
Hero: "Launch a Shopify store built to sell" + subhead + {{cta}}
Problem → Solution ({{service}}) → Benefits → FAQ → Final CTA {{cta}}
```
