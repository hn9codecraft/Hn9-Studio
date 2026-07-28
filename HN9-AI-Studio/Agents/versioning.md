# Versioning

Everything that affects output is versioned so runs are reproducible and changes are auditable.

## Semantic Versioning (MAJOR.MINOR.PATCH)
- **MAJOR** — breaking contract change (input/output shape, removed field).
- **MINOR** — backward-compatible capability (new optional field, new rule).
- **PATCH** — fixes/tuning with no contract change (prompt wording, thresholds).

## Agent Version
- Declared in each agent's `agent.json > version` and echoed in every log/handoff.
- A breaking change bumps MAJOR and requires updating the orchestrator's compatibility matrix.

## Prompt Version
- Each Prompt Engine template (`../Prompts/templates/*.md`) carries a version footer.
- Agents record the `promptVersion` they used; changing a template's output behavior bumps its
  version.

## Workflow Version
- The pipeline definition (order, branches, gates) is versioned as a whole.
- A run pins `workflowVersion` so it can be replayed exactly.

## Brand Version
- The Brand Brain files carry `version`/`lastUpdated` (`../Brand`). Runs record which Brand version
  they consumed.

## Compatibility matrix
- The orchestrator maintains allowed `(workflowVersion → agentVersion range → promptVersion range)`
  combinations.
- Incompatible combinations are rejected at ingress, not discovered mid-run.

## Reproducibility
- Every run stores `{ workflowVersion, agentVersions{}, promptVersions{}, brandVersion }`.
- Given the same versions + inputs, output is reproducible (subject to provider determinism).

## Change management
- Version bumps are logged; MAJOR bumps require regression tests (`testing.md`) to pass.
- Deprecations are announced via the compatibility matrix before removal.

**Version:** 1.0.0 · **Last updated:** 2026-07-18
