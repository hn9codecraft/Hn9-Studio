# Testing

Test strategy for a documentation-first, contract-driven system. These are **checklists and
specifications**; no test code is included in this milestone.

## Unit Test Checklist (per agent)
- [ ] Valid input → output matches Output Contract.
- [ ] Missing required variable → `failed` with the correct missing-field error (no model call).
- [ ] Missing brand data → `needs_review`, no fabrication.
- [ ] Each Decision Rule branch produces the expected routing/output.
- [ ] Each Validation Rule catches a crafted violation.
- [ ] Retry Rules: transient error retries; deterministic error does not.
- [ ] Output language honors `{{language}}`; brand terms untranslated.
- [ ] Logs contain all required fields (`logging.md`).

## Integration Checklist (agent-to-agent)
- [ ] Handoff event payload valid; context appended, not overwritten.
- [ ] Downstream agent reads only its declared context sections.
- [ ] Parallel fan-out dispatches all applicable agents; fan-in barrier waits for all.
- [ ] Skipped agents emit `skipped` and don't block the barrier.
- [ ] Version compatibility matrix enforced at ingress.

## Workflow Checklist (end-to-end)
- [ ] Each deliverable type runs its correct agent subset (`orchestrator.md` conditionals).
- [ ] Full social-post and full video pipelines complete within performance targets.
- [ ] Human-approval gate blocks Publisher until `approved`.
- [ ] `rejected` loops back to Review (max 2 cycles) then escalates.
- [ ] Final asset contains exactly one primary CTA and passes all validation gates.

## Regression Checklist
- [ ] Golden inputs → golden outputs stored per version; diffs reviewed on any bump.
- [ ] MAJOR version bump requires full regression pass.
- [ ] Brand change (e.g. new color/CTA) propagates correctly to all outputs.
- [ ] Prompt template change doesn't break dependent agents' contracts.

## Manual QA Checklist
- [ ] On-brand voice, tone and visuals (spot-check against `../Brand`).
- [ ] Factual accuracy; no hallucinated services/stats/clients.
- [ ] Platform fit (length, aspect ratio, hashtags, emojis).
- [ ] Language quality reads native (`en` / `hi` / `gu`).
- [ ] CTA correct and working link.
- [ ] No sensitive/placeholder data leaked.

## Test data
- Maintain fixtures per language, platform and deliverable type.
- Include adversarial fixtures for prompt injection and banned content (`security.md`).

**Version:** 1.0.0 · **Last updated:** 2026-07-18
