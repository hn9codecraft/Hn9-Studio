# HN9 M11 AUTO AGENT

This file is the execution procedure for a future Cursor Agent. It does not implement any sprint.

Authoritative files:

| Role | File |
|---|---|
| Execution procedure | `HN9-M11-AUTO-AGENT.md` (this file) |
| Sprint scope | `HN9-M11-SPRINT-PROMPTS.md` |
| Execution state and history | `HN9-M11-AUTO-OUTPUT.md` |

Application code lives under `HN9-AI-Studio/` relative to this repository root. Git commands run from this repository root.

## A. Purpose

Execute the approved M11 sprint sequence from the Sprint Prompts file.

Execution model:

1. Read state in `HN9-M11-AUTO-OUTPUT.md`.
2. Identify `CURRENT_SPRINT`.
3. Read only that sprint's prompt in `HN9-M11-SPRINT-PROMPTS.md`.
4. Inspect Git state.
5. Execute that prompt (IMPLEMENT).
6. Run tests, build, migrations, security, and regression.
7. Apply the sprint's live-validation gate.
8. Verify that prompt's acceptance criteria (GATE CHECK).
9. If accepted, run the AUTOMATIC GIT INTEGRATION gate.
10. Write the complete result to `HN9-M11-AUTO-OUTPUT.md` (Human Summary, Full Technical Result, Git Integration Result, Dashboard).
11. Continue only when the acceptance gate and Git Integration Gate both allow it.
12. Stop on a blocker.

Final Auto-Agent order for every sprint:

IMPLEMENT → TEST → BUILD → REGRESSION → SECURITY → GATE CHECK → GIT INTEGRATION → OUTPUT UPDATE → NEXT SPRINT

A sprint prompt line that says "do not continue to the next sprint" means: do not implement the next sprint inside the current sprint. After the current sprint is recorded and Git-integrated when required, continuation is a new execution and happens only when section H and the Git Integration Gate allow it.

Never continue to the next sprint before Git integration and output update are complete when the sprint was accepted.

## B. Source Of Truth

Use only:

1. `HN9-M11-SPRINT-PROMPTS.md`
2. `HN9-M11-AUTO-OUTPUT.md`
3. Existing approved M11 project documentation and the code already accepted for M11.0–M11.5

Do not invent sprint scope. Do not silently add features. Do not skip a required gate.

If those sources disagree, stop and record the conflict. Do not pick a wider scope.

## C. Current Known State

Accepted state at the time this control system was created:

- M11.0 — PASS
- M11.1 — PASS
- M11.2 — IMPLEMENTED / TESTS PASS / LIVE VALIDATION PENDING
- M11.3 — IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING
- M11.4 — IMPLEMENTATION VERIFIED / LIVE GPT VALIDATION PENDING
- M11.5 — PASS

Next sprint: M11.6

Known pre-existing uncommitted work that must be preserved, not reverted, and not committed by this agent:

- M10 Gemini video-download fix
- M11.0 through M11.5 implementation

The OpenAI and image-provider API keys are intentionally unavailable. Do not restore them.

Before the first sprint, snapshot `git status`, `git diff --stat`, and `git diff --name-only`. Every path that is already dirty is `PRE-EXISTING CHANGES`.

## D. Strict M11 Rules

- Follow the current sprint prompt exactly.
- Preserve M10 behavior and workflows.
- Preserve the existing Gemini video-download fix.
- Preserve M11.0–M11.5 work.
- Never run `migrate:fresh`.
- Never reset, clean, stash, or revert existing work.
- Never modify `.env` unless the current sprint prompt explicitly permits that specific change.
- Never expose API keys, authorization headers, or secrets.
- Never commit, push, merge, or rebase during implementation, testing, or while a sprint is BLOCKED.
- After an accepted gate only, run AUTOMATIC GIT INTEGRATION exactly as documented below.
- Never force push, never `reset --hard`, never rewrite remote history.
- Never bypass review.
- Never fake provider success.
- Never create placeholder AI media.
- Never silently repair broken AI output.
- Never skip tests.
- Never skip regression.
- Never claim PASS without the evidence required by that sprint.
- Never claim Git success without verification evidence.

## E. Git Safety (Pre-Sprint Inspection)

Before each sprint run:

```
git status
git status --short
git diff --stat
git diff --name-only
git branch --show-current
git fetch origin
```

Preserve pre-existing dirty files during implementation.

In the sprint result, list two disjoint sets:

- `PRE-EXISTING CHANGES` — dirty before this sprint started
- `CURRENT SPRINT CHANGES` — paths this sprint created or edited

During IMPLEMENT / TEST / BUILD / REGRESSION / SECURITY / GATE CHECK:

- Do not stage.
- Do not commit.
- Do not `git add`.
- Do not push.
- Do not merge.

Git write operations are allowed only inside AUTOMATIC GIT INTEGRATION after the sprint acceptance gate passes.

## AUTOMATIC GIT INTEGRATION

The Auto-Agent must automatically integrate each accepted sprint into Git ONLY after that sprint's acceptance gate passes.

For every accepted sprint:

1. Inspect Git state.
2. Identify pre-existing changes.
3. Identify current sprint changes.
4. Run secret/security checks.
5. Stage ONLY the current sprint's intended changes plus explicitly approved control-file changes.
6. NEVER stage `.env` or secrets.
7. Create a dedicated commit for the sprint.
8. Push the sprint branch.
9. Synchronize with remote main.
10. Merge the sprint branch into main.
11. Push main.
12. Verify local `main` and `origin/main` point to the expected commit.
13. Verify working tree.
14. Update `HN9-M11-AUTO-OUTPUT.md`.
15. Continue to the next sprint only after Git integration succeeds.

### Git Branch Strategy

Dedicated branches:

| Sprint | Branch |
|---|---|
| M11.6 | `m11/m11-6-video-engine` |
| M11.7 | `m11/m11-7-video-generation` |
| M11.8 | `m11/m11-8-continuity` |
| M11.9 | `m11/m11-9-scene-review` |
| M11.10 | `m11/m11-10-video-edit-extend` |
| M11.11 | `m11/m11-11-audio` |
| M11.12 | `m11/m11-12-timeline` |
| M11.13 | `m11/m11-13-renderer` |
| M11.14 | `m11/m11-14-final-review` |
| M11.15 | `m11/m11-15-export` |
| M11.16 | `m11/m11-16-usage` |
| M11.17 | `m11/m11-17-hardening` |

Before creating or switching:

```
git status
git branch --show-current
git fetch origin
```

The sprint branch must start from the current up-to-date `origin/main` after the previous sprint has been successfully merged.

### Pre-Existing Changes Rule

Before staging:

```
git status --short
git diff --stat
git diff --name-only
```

Separate:

- A. PRE-EXISTING CHANGES
- B. CURRENT SPRINT CHANGES

Do NOT accidentally include unrelated pre-existing files.

If the working tree contains pre-existing uncommitted work that belongs to an earlier sprint and has not yet been committed, do NOT silently absorb it into the current sprint commit.

Instead:

- identify it
- determine whether it is part of the approved prior sprint
- if ownership is unclear, STOP and report `USER REVIEW REQUIRED`

Do not guess ownership of changes.

### Secret / Security Check Before Staging

Before `git add`, check candidate files for:

- API keys
- bearer tokens
- passwords
- database credentials
- private keys
- authentication secrets
- `.env` contents
- credential files
- local-only secrets
- URLs containing secrets

NEVER commit:

- `Backend/.env`
- `.env`
- `.env.*`
- credentials files
- private keys
- local auth tokens
- machine secrets

Never print secret values.

If a secret is discovered in a candidate file:

STOP. Do not commit it. Do not automatically rewrite user secrets.

Record:

```
GIT INTEGRATION — BLOCKED
REASON: SECRET DETECTED
```

### Staging Rule

Do NOT use `git add .` unless the entire working tree has been explicitly verified to contain ONLY current approved sprint changes and safe control files.

Prefer explicit staging of identified sprint files.

After staging:

```
git status
git diff --cached --stat
git diff --cached --name-only
```

Verify:

- only intended sprint files are staged
- no `.env`
- no secrets
- no unrelated files
- no generated runtime files
- no temporary files

### Commit Rule

Create exactly ONE commit per completed sprint.

| Sprint | Commit message |
|---|---|
| M11.6 | `feat(m11.6): complete story video engine` |
| M11.7 | `feat(m11.7): complete real video generation` |
| M11.8 | `feat(m11.8): complete story continuity engine` |
| M11.9 | `feat(m11.9): complete scene review and versioning` |
| M11.10 | `feat(m11.10): complete video edit and extend` |
| M11.11 | `feat(m11.11): complete audio studio` |
| M11.12 | `feat(m11.12): complete timeline and transitions` |
| M11.13 | `feat(m11.13): complete final renderer` |
| M11.14 | `feat(m11.14): complete final review and rework` |
| M11.15 | `feat(m11.15): complete export and secure delivery` |
| M11.16 | `feat(m11.16): complete usage and cost tracking` |
| M11.17 | `feat(m11.17): complete production hardening` |

Do NOT create multiple random commits for one sprint. Do NOT amend an old sprint commit.

### When To Commit

A sprint may be committed only when:

- implementation complete
- required tests pass
- build passes
- migrations pass
- regression passes
- security passes
- sprint acceptance gate passes

`PASS — IMPLEMENTATION COMPLETE` with `Live Validation: PENDING` is commit-ready. Record the pending live line in the output. Do not claim a live provider success in the commit message.

Do NOT treat BLOCKED as commit-ready. A missing API credential is not BLOCKED.

### Blocked Sprint Git Rule

If the sprint is BLOCKED:

DO NOT:

- commit
- push
- merge
- continue to next sprint

Instead:

1. Write HUMAN SUMMARY.
2. Write FULL TECHNICAL RESULT.
3. Update dashboard.
4. Record blocker.
5. Set:

```
CURRENT_SPRINT: M11.X
STATUS: BLOCKED
ACTION: USER REVIEW REQUIRED
```

Then STOP.

### Push Rule

After successful commit:

```
git push -u origin <sprint-branch>
```

Requirements:

- normal push
- no `--force`
- no force-with-lease
- no history rewrite

Verify the remote branch exists and points to the sprint commit.

### Remote Main Sync

Before merge:

```
git fetch origin
git log --oneline origin/main..HEAD
git log --oneline HEAD..origin/main
```

The sprint branch must be based on the latest acceptable `origin/main`.

If remote main has moved, safely synchronize without destroying history.

Never:

```
git reset --hard origin/main
```

Never force push.

If conflicts occur: STOP. Do not guess conflict resolution. Set `USER REVIEW REQUIRED` and record the conflicting files.

### Merge Rule

After successful branch push and clean synchronization:

1. Switch to `main`.
2. `git pull --ff-only origin main`
3. Merge the sprint branch:

```
git merge --no-ff <sprint-branch>
```

4. Verify merge.
5. Push main:

```
git push origin main
```

Do NOT force push, squash unless explicitly requested, rewrite existing history, or delete important commits.

The merge commit must preserve sprint history.

### Post-Merge Verification

After pushing main:

```
git fetch origin
git rev-parse main
git rev-parse origin/main
git log --oneline --decorate -10
git status --short
```

`main` and `origin/main` must match.

Also verify:

- sprint commit exists in history
- merge exists where applicable
- `origin/main` contains the sprint
- no intended sprint changes remain uncommitted
- no secrets committed
- no unrelated files included

### Branch Cleanup

Do NOT automatically delete the sprint branch unless repository policy explicitly requires it.

Keep it available for traceability. Do NOT delete remote branches automatically.

### Control File Commits

`HN9-M11-AUTO-AGENT.md`, `HN9-M11-SPRINT-PROMPTS.md`, and `HN9-M11-AUTO-OUTPUT.md` are control files.

If they are modified during an active sprint by the Auto-Agent:

- include them only when the modification is intentionally part of the approved current sprint's output
- never accidentally commit unrelated control-file changes
- preserve previous execution history

Do NOT commit a control-file update as a fake application sprint.

### Auto-Output Git Report

After every successful Git integration, update `HN9-M11-AUTO-OUTPUT.md` with:

- Sprint branch
- Commit hash
- Commit message
- Files committed
- Secret scan
- Push result
- Main sync result
- Merge commit
- Main push result
- Local main
- Origin/main
- Working tree
- Force push used: NO
- Status: MERGED

### Human Summary Git

The HUMAN SUMMARY must also mention:

- sprint code completed
- Git commit created
- branch pushed
- merged into main
- main pushed
- final main verification

Example:

```
M11.6 completed and passed all required checks.
The sprint was committed on its dedicated branch.
The branch was pushed and merged into main.
origin/main was verified at the expected commit.
Ready for M11.7.
```

### Dashboard Git State

After successful Git integration:

```
M11.6 — PASS — MERGED
M11.7 — READY
```

If blocked before Git:

```
M11.7 — BLOCKED
ACTION: USER REVIEW REQUIRED
```

Never show PASS as fully complete before Git integration when Git integration is required by the sprint workflow. Use `PASS — MERGED` only after main push and verification succeed.

### No-Compromise Git Rules

NEVER:

- force push
- force merge
- reset --hard
- git clean
- destructive cleanup
- overwrite remote history
- commit secrets
- commit `.env`
- commit API keys
- commit credentials
- silently absorb unrelated changes
- merge a BLOCKED sprint
- continue after a failed Git gate
- invent a commit hash
- invent a successful push
- claim main is updated without verification

## F. Sprint Execution

Execute one sprint at a time. Do not start two M11 sprints in the same run. Do not jump ahead.

Before editing code:

1. Read that sprint's exact prompt.
2. Inspect the current implementation named by the prompt.
3. Inspect `HN9-M11-AUTO-OUTPUT.md`.
4. Inspect Git state and separate pre-existing vs current work.
5. Follow the prompt.

## G. Stop Conditions

Stop immediately when any of these is true:

- A required test fails.
- A required build fails.
- A migration fails.
- A security test fails.
- An IDOR is found.
- Data integrity fails.
- A required implementation condition fails.
- The sprint verdict is BLOCKED.
- The sprint prompt says STOP.
- The sprint prompt is ambiguous about who is authorized to approve, or about any other permission or security rule.

When stopped, follow the Blocked State section. Do not start the next sprint. Do not write a fake PASS. Wait for the user.

## H. Pass Conditions

Advance only when the current sprint prompt's acceptance criteria are satisfied and its stated verdict is allowed.

For M11.7 through M11.17, `PASS — IMPLEMENTATION COMPLETE` continues even when `Live Validation: PENDING`. Missing provider credentials are not a failed gate. Do not invent a live provider result. The separate M11 LIVE VALIDATION PHASE runs only after M11.17, when the user has configured credentials.

Write the Human Summary and the Full Technical Result before reading the next sprint prompt. After an accepted gate, complete AUTOMATIC GIT INTEGRATION, then update the Git Integration Result and dashboard to `PASS — MERGED`. Follow the Pass / Continue State section. Never continue before Git integration succeeds when the sprint was accepted.

## I. Live Validation Policy

Implementation acceptance and live-provider validation are separate phases. Missing live credentials must not block implementation progress.

For M11.7 through M11.17, a sprint may be marked `PASS — IMPLEMENTATION COMPLETE` when the code, schema, API, UI, tests, security, build, and regressions pass, including fake or mock adapter paths where credentials are unavailable, and no critical implementation defect remains.

Record `Live Validation: PENDING` on that same sprint when a real third-party call did not happen. That pending line does not block the next sprint, and it is not a claim that a provider succeeded.

`BLOCKED` is for a failed test, build, migration, security check, or unfinished required implementation. It is not for a missing API key.

Do not ask for API keys during these sprints. Do not restore keys. Do not invent live provider results.

After M11.17 is implementation-complete, the M11 LIVE VALIDATION PHASE in `HN9-M11-SPRINT-PROMPTS.md` is the only place real provider execution is required. Do not run that phase until the user configures credentials.

## J. Output File

`HN9-M11-AUTO-OUTPUT.md` is the persistent execution log. After every sprint, update it without erasing earlier sprint results.

The Human Summary appears before the Full Technical Result. Do not replace the technical result with the summary. Both are required.

On a successful continuation, follow the Pass / Continue State section. On a block, follow the Blocked State section.

### Human Summary Requirement

After every sprint, write a Human Summary into that sprint's section in `HN9-M11-AUTO-OUTPUT.md`.

The Human Summary explains, in simple language:

- what was completed
- what passed
- what is pending
- blockers
- important risks
- next sprint
- whether execution continued or stopped

Target length: 5–10 lines when practical. Every sentence must match evidence from the sprint. Never invent success.

Example of a passing sprint. This example is not a recorded result:

```
### HUMAN SUMMARY

M11.6 provider engine foundation was completed.
Capability routing and provider abstraction were verified.
Tests and build passed.
No real provider call was required in this sprint.
The sprint was committed on its dedicated branch.
The branch was pushed and merged into main.
origin/main was verified at the expected commit.
Ready to proceed to M11.7.
```

Example of a blocker. This example is not a recorded result:

```
### HUMAN SUMMARY

M11.7 implementation completed, but live video validation failed.
No valid video was produced.
No fake media was created.
No Git commit, push, or merge was performed.
Execution stopped at M11.7 as required.
User review is required before continuing.
```

In the output file, place the same content under `#### HUMAN SUMMARY`.

### Full Technical Result Requirement

After the Human Summary, write a Full Technical Result for the same sprint. Required fields, in this order:

- Implementation
- Files
- API
- Database
- Tests
- Build
- Migration
- Security
- Regression
- Live Validation
- Provider/API Calls
- Git Integration (branch, commit, push, merge, main verification, force-push: NO)
- Blockers
- Next Sprint

Leave a field empty of claims when that check did not run. Write the evidence that did run. `Next Sprint` stays empty when the gate does not allow continuation. For BLOCKED sprints, Git Integration Status must be `BLOCKED` or `NOT ATTEMPTED` with no commit/push/merge claimed.

In the output file, place these fields under `#### FULL TECHNICAL RESULT`.

### Live Execution Dashboard Requirement

The top of `HN9-M11-AUTO-OUTPUT.md` contains `## LIVE EXECUTION DASHBOARD`. Update that dashboard after every sprint state change, before reading the next sprint prompt.

Each line is one sprint and one status. Dashboard statuses are only:

- `PENDING` — the sprint has not started
- `READY` — the sprint may start, and no earlier gate is open
- `RUNNING` — the sprint has started and its result is not final
- `PASS`
- `PASS — IMPLEMENTATION COMPLETE` — M11.7–M11.17 implementation gate passed. Live validation may still be pending in the sprint result and does not block the next sprint.
- `PASS — MERGED` — acceptance gate passed and Git Integration Gate completed
- `IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING`
- `BLOCKED`
- `USER REVIEW REQUIRED`
- `COMPLETE`

Do not invent another dashboard label except `PASS — MERGED` after verified Git integration. Never show bare `PASS` as the final dashboard line for an accepted M11.6–M11.17 sprint until Git integration has succeeded; use `PASS — MERGED` after main verification. M11.0 through M11.5 stay on the dashboard as already accepted history. Do not change a historical dashboard line unless that same sprint is the one being recorded.

The Approved History section still records the original M11.2 verdict `IMPLEMENTED / TESTS PASS / LIVE VALIDATION PENDING`. The dashboard shows that same sprint as `IMPLEMENTATION VERIFIED / LIVE VALIDATION PENDING`, which is the allowed dashboard label for that pending live state. Do not rewrite either line to force them into one sentence. Do not treat the history line as a second live status.

Also update `## HUMAN STATUS SUMMARY` after every sprint. Keep it short enough for a non-technical reader. It states the current sprint, current status, completed sprints, implementation-verified live-pending sprints, the next sprint, and the blocker. Use `None` when there is no blocker.

### Current Sprint State

The output file always contains top-level `CURRENT_SPRINT` and `STATUS`.

Before any remaining sprint starts, the file stays:

```
CURRENT_SPRINT: M11.6
STATUS: READY
```

When execution of a sprint begins, set the top-level `STATUS` to `RUNNING`, set that sprint's `STATUS` to `RUNNING`, and set its dashboard line to `RUNNING`. Set `STARTED` to the actual start time. Do not read a later sprint prompt while this one is `RUNNING`.

When the current sprint's prompt accepts `PASS` and continuation is allowed:

```
CURRENT_SPRINT: <next sprint>
STATUS: READY
```

The completed sprint's dashboard line becomes `PASS`. The next sprint's dashboard line becomes `READY`. There is no sprint after M11.17. When M11.17's own prompt accepts completion and every earlier required sprint is complete:

```
CURRENT_SPRINT: NONE
STATUS: COMPLETE
```

When a sprint blocks, leave `CURRENT_SPRINT` on that sprint:

```
CURRENT_SPRINT: M11.X
STATUS: BLOCKED
ACTION: USER REVIEW REQUIRED
```

Remove `ACTION` only after the user resolves the block. Do not add `ACTION` while status is `READY` or `RUNNING`.

### Blocked State

When a sprint is BLOCKED:

1. Update the live dashboard. That sprint's line becomes `BLOCKED`.
2. Update `CURRENT_SPRINT` to the blocked sprint.
3. Set top-level `STATUS` to `BLOCKED`.
4. Add `ACTION: USER REVIEW REQUIRED`.
5. Write the Human Summary. It must say that execution stopped and that no Git commit/push/merge occurred.
6. Write the Full Technical Result.
7. Record Git Integration Status as `BLOCKED` or `NOT ATTEMPTED`.
8. Record the exact failed stage in `Blockers`.
9. Record the exact sanitized error in `Blockers`. Do not include secrets, keys, or authenticated URLs.
10. Record the impact in the Human Summary and in `Blockers`.
11. Record the required user action in the Human Summary and in `Blockers`.
12. Update the Human Status Summary. Set `Blocker` to the blocked sprint.
13. Stop immediately. Do not commit. Do not push. Do not merge. Do not read the next sprint prompt. Do not execute the next sprint.

Also set that sprint's `STATUS` to `BLOCKED`, set `COMPLETED`, and set `VERDICT` to the prompt's exact blocked verdict. Leave `Next Sprint` empty.

### Pass / Continue State

When a sprint passes:

1. Write the Human Summary (may be finalized after Git with Git lines included).
2. Write the Full Technical Result.
3. Run AUTOMATIC GIT INTEGRATION.
4. Record the Git Integration Result in `HN9-M11-AUTO-OUTPUT.md`.
5. Update the live dashboard to `PASS — MERGED`.
6. Update `CURRENT_SPRINT`.
7. Mark the next sprint `READY` on the dashboard and in that sprint's `STATUS`.
8. Update the Human Status Summary.
9. Only then read the next sprint prompt.
10. Continue exactly one sprint at a time.

Never continue before both the Human Summary and the Full Technical Result are written. Never continue before Git Integration Status is `MERGED` for an accepted sprint. Never start the next sprint in the same breath as an unfinished result or failed Git gate.

Set the passed sprint's `STATUS` to `PASS — MERGED` (or keep `STATUS: PASS` with Git Integration Status `MERGED` and dashboard `PASS — MERGED`), set `COMPLETED`, and set `VERDICT` to the prompt's exact pass verdict. Set `Next Sprint` to the following sprint id. Top-level `STATUS` becomes `READY` for that next sprint. Do not set top-level `STATUS` to `PASS`. `PASS` / `PASS — MERGED` belongs to the completed sprint.

`PASS — IMPLEMENTATION COMPLETE` with `Live Validation: PENDING` uses this continue path. Git integration is required. The dashboard may read `PASS — MERGED` for the implementation, and the sprint result must still say `Live Validation: PENDING`. Do not claim live provider success.

### Execution History

Do not erase previous sprint results. Do not delete evidence. Do not overwrite a previous sprint result with a contradictory result.

When a sprint changes from `RUNNING` to `PASS`, or from `RUNNING` to `BLOCKED`:

- preserve the result already written for earlier sprints
- update the current sprint's state in its own section
- keep the started time, the checks that ran, and the verdict

A later sprint may add its own section. It must not rewrite an earlier sprint's Human Summary or Full Technical Result.

### Sprint Output Template

Every sprint section in `HN9-M11-AUTO-OUTPUT.md` uses this shape. The Human Summary comes before the Full Technical Result.

```
### M11.X

STATUS:
STARTED:
COMPLETED:
VERDICT:

#### HUMAN SUMMARY

#### FULL TECHNICAL RESULT

Implementation:

Files:

API:

Database:

Tests:

Build:

Migration:

Security:

Regression:

Live Validation:

Provider/API Calls:

#### GIT INTEGRATION

Sprint Branch:
Commit:
Commit Message:
Files Committed:
Secret Scan:
Push:
Main Sync:
Merge:
Main Push:
Local Main:
Origin/Main:
Working Tree:
Force Push: NO
Status:

Blockers:

Next Sprint:
```

Until a sprint starts, `STATUS` is `PENDING` except for the current ready sprint, which is `READY`. The Human Summary says `Not started yet.` The technical fields stay blank. Do not write a PASS, a provider result, a file list, or a Git MERGED claim before the work happens. Git Integration `Status` may be `MERGED` only after main push and verification succeed. Use `BLOCKED` or `NOT ATTEMPTED` when Git integration cannot safely complete or was not allowed.

### Authorization Ambiguity

If a sprint prompt is ambiguous about an authorization or security rule, stop. Record `USER REVIEW REQUIRED`. Do not invent a role or a permission model.

M11.9 is the known case. Its prompt says to reuse the M10 submit-then-approve shape, and it also says the owner may approve. M10 approval is an admin action. Those two statements do not name one approver.

When execution reaches M11.9, before implementing approval, stop and record:

- top-level `STATUS: BLOCKED`
- `ACTION: USER REVIEW REQUIRED`
- dashboard line `M11.9 — USER REVIEW REQUIRED`
- Human Summary stating that the approver is not defined
- Full Technical Result with the conflict in `Blockers` and `Security`

Do not choose owner approval. Do not choose admin approval. Do not implement the approval actor until the user resolves it. Review, comment, and versioning work that the prompt defines without choosing the approver may be recorded as not started while this stop is in force. Do not redesign the M11.9 prompt.

## K. Sequential Execution

Order:

M11.6, M11.7, M11.8, M11.9, M11.10, M11.11, M11.12, M11.13, M11.14, M11.15, M11.16, M11.17

There is no sprint after M11.17 in this system.

Stop whenever section G or the current sprint gate says stop.

## L. No Unauthorized Scope

Do not:

- Create a sprint that is not in `HN9-M11-SPRINT-PROMPTS.md`
- Merge two sprint scopes into one execution
- Redesign the roadmap
- Change the approved Project Story architecture
- Add features that the current prompt does not require
- Skip a required section because it is difficult
- Mark incomplete work complete

## M. Final M11 Result

Write `M11 — COMPLETE` in `HN9-M11-AUTO-OUTPUT.md` only after M11.6 through M11.17 each have a recorded verdict that their own prompt accepts as complete. At that point set `CURRENT_SPRINT: NONE` and `STATUS: COMPLETE`.

Otherwise report the exact sprint that is incomplete or blocked, and do not write `M11 — COMPLETE`.
