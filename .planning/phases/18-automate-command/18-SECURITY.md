---
phase: 18-automate-command
plan: "01"
asvs_level: 1
audited: "2026-04-08"
auditor: gsd-security-auditor
---

# Security Audit — Phase 18-01: Automate Command

**Threats Closed:** 2/2
**ASVS Level:** 1
**block_on:** critical

## Threat Verification

| Threat ID | Category | Disposition | Status | Evidence |
|-----------|----------|-------------|--------|----------|
| T-18-01 | Tampering | accept | CLOSED | `app/Commands/SetupCommand.php:22` — delegation via `$this->call('automate', [...])` routes through Laravel Artisan kernel; no new input parsing or trust boundary crossing introduced |
| T-18-02 | Elevation of Privilege | accept | CLOSED | `app/Commands/AutomateCommand.php:68,141,148` — plist path derived from user home directory resolver; launchctl operates on `~/Library/LaunchAgents` (user-space LaunchAgent); no `sudo`, no system-level daemon path (`/Library/LaunchDaemons`), no privilege escalation present |

## Accepted Risks Log

### T-18-01 — Tampering: SetupCommand delegation

- **Component:** `app/Commands/SetupCommand.php`
- **Risk:** An attacker who can invoke `setup` could potentially exercise the `automate` command through the delegation path.
- **Acceptance Rationale:** The delegation uses `$this->call('automate')` which routes through the Laravel Artisan kernel — the identical execution path as invoking `automate` directly. No additional attack surface is introduced. The `--hour` and `--minute` option values forwarded are the same already-received values, not re-parsed from a new trust source.
- **Owner:** Gary Kovar
- **Review Date:** 2026-04-08

### T-18-02 — Elevation of Privilege: AutomateCommand (launchctl)

- **Component:** `app/Commands/AutomateCommand.php`
- **Risk:** The command writes a plist file and calls `launchctl load` / `launchctl unload`, which could be misused to register a persistent process.
- **Acceptance Rationale:** This is the intended and declared purpose of the command. The plist path is always resolved relative to the current user's home directory (`~/Library/LaunchAgents`), which is a user-space LaunchAgent requiring no elevated privileges. The command contains no `sudo` invocation and does not target the system-level LaunchDaemons path (`/Library/LaunchDaemons`). The scope is limited to the installing user's session.
- **Owner:** Gary Kovar
- **Review Date:** 2026-04-08

## Unregistered Flags

None. SUMMARY.md `## Threat Flags` explicitly states no new threat surface was introduced; the `automate` command's attack surface (launchctl, user LaunchAgent) was already present in the prior `setup` command.
