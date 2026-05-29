# Copland Workflow Model

**Purpose:** Concrete contract describing what Copland does and explicitly does not do across the full task lifecycle. Compare proposed changes against this before adding scope.

**Core value (from PROJECT.md):** A reliable overnight agent that opens merge-ready PRs without intervention.

**Hard line:** Copland stops at draft PR. It never merges, deploys, or touches main.

---

## Lane summary

| Lane | Stages | Status |
|---|---|---|
| Intake | 1 Capture, 2 Triage, 3 Clarify | Active |
| Definition | 4 Scope, 5 Plan, 6 AC, 7 QA, 8 Tech Design | Active (4 in plan, 8 out) |
| Execution | 9 Implementation, 10 Local Verify, 11 Tests | Active |
| Review | 12 Code Review, 13 Revision | Out (human-owned) |
| Delivery | 14 PR Prep, 15 Human Review, 16 Deploy | 14–15 active, 16 out |
| Closeout | 17 Post-deploy, 18 Docs, 19 Closeout, 20 Learning | 17 out; 18–20 active |

---

## Stage-by-stage contract

### Intake

#### 1. Capture
- **Sources:** GitHub Issues, Asana, Manual CLI.
- **Per-project rule:** GitHub xor Asana — a project is configured with exactly one external source of truth.
- **Manual entry:** `copland task add` MUST create a canonical ticket in the project's source (GitHub Issue or Asana task) before doing anything else. No untracked work.
- **Out:** Slack, Email forwarding.

#### 2. Triage
- **Minimal.** Label-based prefilter only (`required: agent-ready`, `blocked: agent-skip` configurable per repo).
- Selector picks one task per run. No explicit priority / risk / complexity scoring.
- **Out:** dependency mapping between tasks, backlog ordering.

#### 3. Clarify
- **No interactive clarification at runtime** — Copland is overnight.
- When the planner detects ambiguity it returns `decision: decline`. Copland then posts the planner's open questions back to the source ticket as a comment. Author answers asynchronously; next run can pick the issue up.
- **Out:** push notifications, pause states, conversational back-and-forth.

### Definition

#### 4. Scope
- **No separate artifact.** Scope lives inside `PlanResult` via `files_to_change`, `blocked_write_paths`, `guardrails`, `max_files_changed` (default 3), `max_lines_changed` (default 250).

#### 5. Planning
- **Artifact:** `PlanResult` (already has decision, branch_name, files_to_read/change, blocked_write_paths, steps, commands_to_run, tests_to_update, success_criteria, guardrails, pr_title, pr_body, max-files/lines, decline_reason, changes).
- **Refinement:** plan should emit `steps` as an **ordered sequence of work** with explicit dependencies between steps — informs executor ordering, NOT separate commits.
- **Out:** migration strategy field, rollback strategy field (covered by guardrails + the plan being declinable).

#### 6. Acceptance Criteria
- **Artifact:** checkbox list emitted by planner.
- **Distribution:** pushed to the source ticket (GitHub issue comment / Asana task description) AND included in the PR body. Human ticks boxes as they verify.
- Source ticket is the source of truth for verification state, not Copland.

#### 7. QA Plan — regression surface map
- **Trigger:** any plan touching code paths used by ≥1 other file outside `files_to_change`.
- **Artifact:** call-site list. Planner / verifier greps for callers of functions, methods, classes in the changed files; emits `regression_surface: [{caller_file, caller_symbol}]` in the plan and surfaces it in the PR body.
- **Not** a manual-test plan. Not browser/device matrices.

#### 8. Technical Design
- **Out.** Copland's scope ceiling (3 files / 250 lines) precludes work that needs one. If a planner judges a task needs a tech design, that's a signal to `decline` and escalate the issue to human triage.

### Execution

#### 9. Implementation
- Executor produces **one atomic commit per task**. Sequence-of-work from the plan informs ordering inside the commit; it does not split it.
- Existing `ClaudeExecutorService` + plan-driven tool whitelist. PR #16 added exact-diff `changes` blocks that the executor applies via `replace_in_file`.

#### 10. Local Verification — mandatory pre-PR gate
Before the PR is opened, Copland runs (in order):
1. **Test suite** (`./vendor/bin/pest`, `php artisan test`, or repo-configured equivalent).
2. **Linter / formatter** (`./vendor/bin/pint` if present). If pint modifies files, amend the commit.
3. **Static analysis** (`phpstan` / `psalm` if configured).
4. **Build** (conditional): `composer install` if `composer.json` changed; `npm run build` if `package.json` or frontend files changed.

**Any red step blocks the PR.** Verification output is summarized into the PR body (see Stage 14).

#### 11. Automated Tests — required for behavior changes
- **Verifier rule:** if the diff modifies executable code AND no test files were touched, block the PR with `tests required`.
- Docs, README, `.copland.yml`, configuration-only changes are exempt.
- This is enforced by `VerificationService`, not the planner — the planner can still legitimately emit `tests_to_update: []` for a pure-docs task.

### Review

#### 12. Code Review
- **Out of Copland's loop.** Human reads PRs.
- `reviewinator` (multi-AI review tool) remains a **separate, user-invoked** tool — not part of `copland run`. Run it manually when you want a second opinion (`reviewinator <pr#>`).

#### 13. Revision
- **Out.** Once Copland opens a draft PR, the work passes to the human. Copland never reads PR review comments, never auto-revises.
- If a fix is needed and the original issue is still relevant, file a new issue or comment on the existing one with `agent-ready` and Copland will pick it up.

### Delivery

#### 14. PR Preparation
PR body MUST include:
- **AC checklist** (from Stage 6).
- **Source ticket link** as `Closes #N` (GitHub) or Asana URL.
- **Local verification summary** (e.g. `Tests: 179 passed | Pint: clean | Phpstan: 0 errors | Build: ok`).
- **Files-touched + regression-surface list** (from Stage 7).

PR title comes from `PlanResult.pr_title`. Always opened as draft.

#### 15. Human Review
- **Draft PR is the gate.** No separate Copland-side approval state machine.
- GitHub PR state IS the workflow state. `draft` = pending. `ready for review` = human-promoted. `merged` = approved + delivered. `closed` = rejected.

#### 16. Delivery / Deployment
- **Out.** Hard line. Copland never marks ready, never merges, never deploys.
- Human owns the merge button.

### Closeout

#### 17. Post-Delivery Verification
- **Out.** Copland doesn't deploy, so nothing to verify after.
- Reopened-issue / `wontfix` / revert signals can be observed by Stage 20 as quality data.

#### 18. Documentation
- **Treated as a normal task type.** If the issue says "update README to reflect X", Copland handles it like any other run.
- **No auto-detection** of "this change needs docs." That's the author's responsibility when filing the issue.

#### 19. Closeout
On successful PR open:
- Remove `agent-ready` label from source ticket.
- Add `agent-pr-opened` label with PR URL.
- **Do NOT close the issue.** `Closes #N` in the PR body handles that on merge.

#### 20. Learning / Memory
- **Per-repo lessons file:** `~/.copland/repos/<repo>/lessons.md`.
- Captures repo-specific gotchas: paths that don't exist, commands that work / fail, common pitfalls, common decline reasons.
- **Loaded into planner's context** on next run for that repo (as part of `repo_summary` / `conventions` injection).
- Curated automatically from run outcomes; tighten or prune manually.
- **Out:** global cross-repo synthesizer, per-task `lessons-learned.md` files committed alongside PRs.

---

## What's explicitly OUT of scope

- Slack / Email task capture
- Interactive clarification or push notifications during runs
- Risk / complexity / priority scoring
- Migration / rollback as plan fields
- Separate `scope.md` / `technical-design.md` / `qa-plan.md` artifacts (data lives in the plan or PR body)
- Code review as a pipeline stage
- Auto-revision after review
- Marking PRs ready, merging, deploying, post-deploy verification
- Auto-documentation of public surface
- Auto-closing issues
- Per-task artifact directories under `.planning/tasks/`
- Cross-repo learning synthesis
- Browser / device / accessibility QA matrices

---

## When to revisit this contract

Reopen this document when any of these change:

1. Copland starts merging or deploying — the hard line moves.
2. A second human starts using Copland — the "personal use" assumption breaks; review-as-pipeline-stage may become worth the complexity.
3. The 3-files / 250-lines ceiling is raised meaningfully — tech design, real scope artifacts, and migration fields become load-bearing.
4. Manual review backlog grows — code-review-as-pipeline-stage may become worth automating.
5. Repo count grows past ~5 — global cross-repo learning may justify the infra.

Adding a stage from "out of scope" without one of those triggers is scope creep. Push back.
