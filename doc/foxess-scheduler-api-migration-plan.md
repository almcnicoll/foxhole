# FoxESS scheduler API migration plan: v1 → v2 → v3

Status: **Stage 1 implemented, then partially reverted the same day** —
`scheduler/get` is on v2; `scheduler/enable` went to v2 and back to v1 after a
live-confirmed regression (see "Stage 1 regression" below). Stages 2 and 3 are still
follow-on options, done only if separately requested — but Stage 3 in particular must
budget time to re-test the group-count issue below against v3 before trusting it, not
assume v3 fixes it.

## Stage 1 regression, found live (2026-09-02)

The day after Stage 1 shipped, a routine push (2 groups) worked fine; adding an override
and repushing (11 groups, `reapplyOverrides()`) failed on both inverters:
`errno 40257: Parameters do not meet expectations. Please reenter`.

Diagnosis (all via direct, read-only-where-possible live calls against the real
account, same discipline as every other FoxESS finding in this project):
1. The failing request body was already correctly shaped (`extraParam` nesting present)
   — so this was never a case of the override path missing the v2 translation. Confirmed
   the same `pushSchedule()`/`toV2Groups()` code Stage 1 added handles both the routine
   and override push paths identically, because they share the exact same function.
2. `properties`/capability discovery, previously assumed v3-only, turned out to already
   be present in v2's `scheduler/get` response for this account — a genuine surprise
   versus the historical library source the plan was based on, but not the cause here.
3. Re-ordering the groups into ascending clock-face time made no difference — ruled out
   an ordering requirement.
4. Bisecting the group list by count did: **8 groups succeeded, 9 failed**, repeatably.
5. The same 9-group payload (reshaped back to v1's flat fields) succeeded against
   `/op/v1/device/scheduler/enable` without complaint.
6. A read-only v3 `scheduler/get` call reported `maxGroupCount: 24` for the same
   device — actively misleading, since v2's real enforced limit for a write is 8.
   **Don't trust a version's reported metadata for a different version's behaviour.**

Root cause: **v2's `scheduler/enable` hard-rejects any push of more than 8 groups**,
with no field-level indication of why (`errno 40257` is FoxESS's generic "didn't like
something" code) — v1 has no such limit, or at least a higher one. This app's modelling
scheduler routinely produces more than 8 groups on its own (a plain run earlier in this
project logged 13), and overrides make it more likely, not less.

Fix shipped: `pushSchedule()` reverted to v1 for both the clear and real-push calls;
`getSchedule()` stayed on v2 (no observed downside, and it's where the `properties` win
lives). `toV2Groups()` was removed as dead code rather than left unused. Verified live:
the exact 11-group payload that failed on v2 succeeds on v1 unchanged, and a real
override push through the app's own `reapplyOverrides()` now completes successfully.

**Before ever attempting Stage 3** (moving `scheduler/enable` to v3), this group-count
behaviour must be re-tested empirically against v3 specifically — do not assume v3's own
`maxGroupCount` figure is trustworthy for the same reason v2's wasn't. If v3 turns out to
have the same or a similar cap, either stage becomes blocked on this app first learning
to collapse/reduce a schedule to fit within it, which is real, separate design work, not
a version-swap detail.

## Why

See CLAUDE.md's "FoxESS scheduler endpoint" note and the API-version comparison done
2026-08-25: this app's schedule read/write calls (`scheduler/get`, `scheduler/enable`)
are on v1. The actively-maintained community reference (`TonyM1958/FoxESS-Cloud`) moved
v1 → v2 (2025-11-30, commit `2b6e7fad`) → v3 (2026-03-22, release `2.9.8`, flagged by its
own maintainer as "potential breaking changes"). v3 adds a `properties` capability block
to `scheduler/get` that would let this app stop guessing about `fdSoc`/`fdPwr` support
per device — the exact gap CLAUDE.md already flags as unconfirmed.

Two endpoints are **not** part of this migration at any stage, because even the current
v3-era reference code keeps them where they are:
- `scheduler/get/flag` / `scheduler/set/flag` (the Mode Scheduler master switch) — v1 in
  every version checked, v1→v2→v3.
- `device/real/query` (battery SoC) and `device/report/query` (generation/usage history)
  — different endpoint families entirely, unaffected by the scheduler versioning.

## Confirmed wire shapes (source: `TonyM1958/FoxESS-Cloud`, read directly, not the FoxESS
doc page — that page scrapes unreliably, confirmed live twice this project)

### v1 (current — matches this app's `ScheduleBuilder` group shape exactly)

```json
POST /op/v1/device/scheduler/enable
{
  "deviceSN": "...",
  "groups": [
    {
      "enable": 1,
      "startHour": 18, "startMinute": 0,
      "endHour": 19, "endMinute": 0,
      "workMode": "ForceCharge",
      "minSocOnGrid": 15,
      "fdSoc": 100,
      "fdPwr": 3000
    }
  ]
}
```

`scheduler/get` returns the same flat shape back.

### v2 (`2b6e7fad`, 2025-11-30)

Same path structure, `/op/v2/device/scheduler/enable` and `/op/v2/device/scheduler/get`.
**The four control fields move into a nested `extraParam` object**; `enable`,
`startHour`/`startMinute`/`endHour`/`endMinute`, and `workMode` stay top-level. Two new
*optional* fields appear (`maxSoc`, `importLimit`/`exportLimit`) — nothing this app needs
yet, but the shape has room for them:

```json
POST /op/v2/device/scheduler/enable
{
  "deviceSN": "...",
  "groups": [
    {
      "enable": 1,
      "startHour": 18, "startMinute": 0,
      "endHour": 19, "endMinute": 0,
      "workMode": "ForceCharge",
      "extraParam": {
        "minSocOnGrid": 15,
        "fdSoc": 100,
        "fdPwr": 3000
      }
    }
  ]
}
```

`scheduler/get`'s response nests the same way. No capability-discovery (`properties`)
exists yet at v2 — that's v3-only.

### v3 (`2.9.8`, 2026-03-22 — "potential breaking changes" per the library's own changelog)

Same `extraParam` nesting as v2, plus:
- **`properties` on `scheduler/get`'s response** — per-device capability discovery:
  which of `maxsoc`/`fdsoc`/`fdpwr`/`importlimit`/`exportlimit`/`pvlimit`/`reactivepower`
  this specific inverter model actually supports, and the real work-mode enum
  (`workmode.enumList`). Also `maxGroupCount` (max number of periods this device
  accepts).
- New optional fields: `pvLimit`, `reactivePower`.
- `isDefault` at the request's top level (`set_schedule`'s body), `isRemainMode` per
  period (marks the 00:00–23:59 fallback period) — **not currently useful to this app**
  and reportedly not fully processed by the public API yet (community-reported: sending
  a default/remain period can desync the FoxESS phone app's own scheduler view — see
  `foxesscommunity.com` topic 3053). Plan: never send `isRemainMode`/a default period;
  filter one out of `scheduler/get` reads the same way the reference library does.
- **v3 rejects unsupported parameters outright** (the library's own words: "this limits
  the testing I can do for different inverters"), and **removes the ability to send a
  disabled period** — there is no top-level `enable` field per period in v3's period
  shape at all. Every period sent is implicitly active.

```json
POST /op/v3/device/scheduler/enable
{
  "deviceSN": "...",
  "groups": [
    {
      "startHour": 18, "startMinute": 0,
      "endHour": 19, "endMinute": 0,
      "workMode": "ForceCharge",
      "extraParam": {
        "minSocOnGrid": 15,
        "fdSoc": 100,
        "fdPwr": 3000
      }
    }
  ]
}
```

```json
// GET /op/v3/device/scheduler/get response (abbreviated)
{
  "result": {
    "enable": 1,
    "maxGroupCount": 8,
    "properties": {
      "maxsoc": {...}, "fdsoc": {...}, "fdpwr": {...},
      "importlimit": null, "exportlimit": null, "pvlimit": null, "reactivepower": null,
      "workmode": {"enumList": ["SelfUse", "Feedin", "Backup", "ForceCharge", "ForceDischarge", "PeakShaving"]}
    },
    "groups": [ {"startHour":18, "startMinute":0, "endHour":19, "endMinute":0, "workMode":"ForceCharge", "isRemainMode": false, "extraParam": {...}} ]
  }
}
```

## Guiding principles for every stage

1. **Live-verify before trusting, same discipline as the BST workaround and
   master-switch fixes** — a request-shape guess that looks right from reference code has
   still been wrong twice this project already (see CLAUDE.md's `setSchedulerFlag()`
   int-vs-bool story). Read-only calls (`scheduler/get`) can be tested freely; the first
   real write on a new version should be a small, deliberate, observed test — not folded
   silently into a routine cron push.
2. **Keep the blast radius at the wire boundary.** This app's internal group shape
   (`ScheduleBuilder`'s output, `schedule_groups` table, `last_pushed_groups_json` diffing,
   dashboard rendering) stays the existing flat v1-shaped array throughout every stage.
   Translation to whatever the wire actually expects happens in one place —
   `FoxessClient` — immediately before the HTTP call. Nothing upstream of `FoxessClient`
   should need to know which scheduler API version is in play.
3. **One stage, one deploy, one thing to roll back if it's wrong.** Each stage below ends
   with its own test → push → deploy, per the user's own staging. Don't start the next
   stage's code until the previous one has been live for at least one real cron cycle
   with no surprises in the API log.
4. **Rollback is `git revert` + redeploy.** No feature flag needed for this — the version
   string is the only thing that changes at each stage, and the previous commit is always
   a working fallback.

---

## Stage 1 — move `scheduler/get` and `scheduler/enable` to v2

**Outcome: `scheduler/get` stayed on v2. `scheduler/enable` shipped to v2, then reverted
to v1 the same day — see "Stage 1 regression" above.** Left below as originally written,
for the record of what was actually tried; treat "move `scheduler/enable` to v2" as
**done and undone**, not as a remaining task.

Lowest-risk stage *on paper*: v2's shape is a well-understood reshape (nest three fields
we already send into `extraParam`), not a behavioural change, and doesn't touch the
master-switch fix or BST workaround logic at all. What the plan didn't anticipate, and
what pure shape-comparison against reference source couldn't have caught, was a hard
group-count limit enforced only on the write side — see the regression note.

### Code changes

- **`src/FoxessClient.php`**:
  - `pushSchedule()`: both calls (the clear, and the real push) move from
    `/op/v1/device/scheduler/enable` to `/op/v2/device/scheduler/enable`.
  - `getSchedule()`: moves to `/op/v2/device/scheduler/get`.
  - Add a small private translation method, e.g. `toV2Groups(array $groups): array`,
    that maps this app's existing flat group shape into v2's `enable`/`startHour`/.../
    `extraParam: {minSocOnGrid, fdSoc, fdPwr}` shape. Called from `pushSchedule()` only —
    the empty-array clear call maps to an empty array trivially, no translation needed
    there.
  - `getSchedulerFlag()` / `setSchedulerFlag()`: **unchanged**, stay on v1 (see "Why"
    above).
- **Everything else** (`ScheduleBuilder`, `Store`, `Runner.php`, `index.php`,
  `schedulers.php`, `override.php`) — no changes. They only ever see the existing flat
  group shape; the reshape happens inside `FoxessClient` on the way out.

### Tests

- Update the existing `pushSchedule()` sequence tests in `tests/self_check.php` (the
  `$recordingClient`/`$alreadyOnClient`/etc. fixtures from the master-switch work) to
  assert the new path (`/op/v2/device/scheduler/enable`) and the new nested body shape
  for the real-push call, while the clear call's body (`groups: []`) is unchanged.
- New unit test for `toV2Groups()` directly: one flat group in, confirm the exact nested
  shape out (top-level `enable`/`startHour`/`startMinute`/`endHour`/`endMinute`/
  `workMode`, `extraParam` holding exactly `minSocOnGrid`/`fdSoc`/`fdPwr` and nothing
  else).
- `getSchedule()` has no internal consumer today (confirmed: only referenced from
  `roadmap.MD` as a manual verification step) — no shape-parsing test needed, just
  confirm the path changed.

### Live verification (before pushing to production)

1. Call the fixed `getSchedule()` (now v2) against a real device — read-only, safe.
   Confirm `errno: 0` and that the returned `groups` are nested under `extraParam` as
   expected.
2. Do one real push via `run-now.php` (or a scoped manual script) and immediately
   `getSchedule()` again — confirm the pushed periods round-trip correctly (times,
   `workMode`, `minSocOnGrid`/`fdSoc`/`fdPwr` values all match what was sent).
3. Check the FoxESS app shows the same schedule the dashboard says it pushed.
4. Watch one real cron cycle's entry in `logs/scheduler.log` / `api-log.php` for any new
   errno before considering the stage done.

### Ship

Test → commit → push → deploy, same as every other change this project has made.

---

## Stage 2 — move `scheduler/get` to v3, add capability storage (only if requested)

Read-only stage — the write path (`scheduler/enable`) stays on v2 from Stage 1 until
Stage 3. This stage exists specifically to let Stage 3 make informed, per-device
decisions instead of guessing.

### Code changes

- **`src/FoxessClient.php`**:
  - `getSchedule()` moves to `/op/v3/device/scheduler/get`.
  - New method, e.g. `getSchedulerCapabilities(): ?array`, parses the v3 response's
    `properties`/`maxGroupCount`/`workmode.enumList` into a small normalised shape, e.g.
    `['maxGroupCount' => int, 'supports' => ['maxSoc' => bool, 'fdSoc' => bool, 'fdPwr' =>
    bool, 'importLimit' => bool, 'exportLimit' => bool, 'pvLimit' => bool,
    'reactivePower' => bool], 'workModes' => string[]]`. Returns `null` if the device
    doesn't support the scheduler at all (mirrors `getSchedulerFlag()`'s existing
    `support: false` → `null` convention).
  - When reading schedule periods back, filter out any `isRemainMode: true` period —
    matching the reference library's own workaround for the same reported desync issue.
- **`src/Store.php`**: new functions `getDeviceCapabilities(string $sn): ?array` /
  `setDeviceCapabilities(string $sn, array $capabilities): void`. Recommend **one
  settings-table entry** (`foxess_device_capabilities_json`, a JSON object keyed by
  device serial) rather than a new table — consistent with how `foxess_device_sns`
  already holds a multi-device list in one key/value row (see CLAUDE.md's "Data
  storage"). A new table would only make sense if capabilities needed independent
  history/timestamps per row, which they don't — this is "current known state per
  device," the same shape `last_pushed_groups_json` already uses for something similar.
- **`src/Runner.php`**: fetch and store each configured device's capabilities once per
  real run (same "only when it might have changed" cadence as the solar forecast's 2h
  staleness check would be reasonable — capabilities won't change often, but a firmware
  update could plausibly add/remove a supported field).
- **`settings.php`** (or a new small section — "feel free to improvise" per the original
  ask): a read-only "Device capabilities" display per configured serial, refreshed from
  the stored JSON — e.g. "supports max SoC: no · supports export limit: no · work modes:
  SelfUse, Feedin, Backup, ForceCharge, ForceDischarge". Purely informational at this
  stage; nothing here should yet let the user *configure* `maxSoc`/`importLimit`/etc. —
  that's a scheduling feature, not a migration concern, and out of scope until Stage 3
  proves the write path is solid.

### Tests

- Unit test `getSchedulerCapabilities()`'s parsing against a fixed sample v3 response
  (support flags true/false/absent, `isRemainMode` filtering).
- Unit test `getDeviceCapabilities()`/`setDeviceCapabilities()` round-tripping through
  the settings table, multiple device serials not clobbering each other.

### Live verification

1. Call `getSchedule()` (v3) read-only against both configured devices — confirm
   `errno: 0`, inspect the real `properties` block for each (they may differ if the two
   inverters are different models).
2. Confirm `isRemainMode` filtering doesn't silently drop a real, user-set period (only
   the literal 00:00–23:59 fallback should ever match).
3. Confirm the settings.php capability display renders sensibly for both devices before
   calling the stage done.

### Ship

Test → commit → push → deploy.

---

## Stage 3 — move `scheduler/enable` to v3 (only if requested)

Highest-risk stage — this is the write path, on the version FoxESS's own most active
third-party integrator calls "potential breaking changes" and describes as rejecting
unsupported parameters. Do this only once Stage 2 has been live long enough to trust the
capability data it's storing.

### Code changes

- **`src/FoxessClient.php`**:
  - `pushSchedule()`'s real-push call (not the clear call — see below) moves to
    `/op/v3/device/scheduler/enable`.
  - Replace `toV2Groups()` with `toV3Groups(array $groups, ?array $capabilities):
    array` — same `extraParam` nesting as v2, but:
    - **Drop the per-period top-level `enable` field entirely** — v3 has no concept of a
      disabled period in the request; every period sent is active by construction (this
      app never sends a disabled period today anyway, so this is a no-op removal, not a
      behaviour change).
    - **Only include `extraParam` fields the device's stored capabilities confirm it
      supports** — always include `minSocOnGrid` (every version so far treats this as a
      baseline field), include `fdSoc`/`fdPwr` only if `$capabilities['supports']['fdSoc'
      ]`/`['fdPwr']` are true. This app has no use for `maxSoc`/`importLimit`/
      `exportLimit`/`pvLimit`/`reactivePower` yet, so never send them regardless of
      support — sending an unused field is pure risk on a version that "rejects
      unsupported parameters," for zero benefit.
    - If `$capabilities` is null (never fetched, or device doesn't support the
      scheduler) — fail loud with a clear `FoxessPushException` rather than guessing;
      Stage 2 should already guarantee this doesn't happen in the normal case, but a new
      device added to `foxess_device_sns` without a capability fetch yet is a real
      possible state.
  - **The clear call (`groups: []`) can stay on v2** or move to v3 alongside the real
    push — an empty array has no shape to get wrong either way, so this is a judgement
    call for whoever implements it; moving both together is simpler to reason about and
    is the recommended default.
  - `getSchedulerFlag()`/`setSchedulerFlag()`: still unchanged, still v1.

### Tests

- Update `toV3Groups()`'s unit tests: confirm the per-period `enable` field is absent,
  confirm `fdSoc`/`fdPwr` are included/excluded correctly based on a fixed `$capabilities`
  fixture, confirm the null-capabilities case throws.
- Update the `pushSchedule()` sequence tests (same fixtures touched in Stage 1) for the
  new path and shape.

### Live verification — the most important checklist in this whole plan

1. **Read capabilities fresh immediately before the first real v3 push** (don't trust
   week-old cached data for this first attempt) — confirm they still match what Stage 2
   discovered.
2. Push one real, small, low-consequence test schedule (e.g. a short SelfUse-only
   window) via `run-now.php` first, not a full real schedule — watch `api-log.php` for
   any `errno` (per the maintainer's own warning, an unsupported-parameter rejection is
   the most likely new failure mode).
3. `getSchedule()` (v3) immediately after — confirm the pushed periods round-trip
   exactly.
4. Confirm the FoxESS app's own Mode Scheduler view looks correct and isn't desynced
   (the specific failure this plan already knows to watch for — see the v3 shape notes
   above on `isRemainMode`).
5. Only once that's clean, let one full real cron-driven push happen and re-check the
   API log and the app again.
6. Watch for at least one full day's real cron cycles before considering this stage
   genuinely done — this is the stage with the least community mileage behind it.

### Ship

Test → commit → push → deploy — but treat "deploy" here as the start of a watch period,
not the end of the task.

---

## Summary table

| Stage | Endpoint(s) moved | Version | Status | New fields used | DB/settings changes | Risk |
|---|---|---|---|---|---|---|
| 1 | `scheduler/get` | v1 → v2 | **Done** | none | none | Low — pure reshape, confirmed |
| 1 | `scheduler/enable` | v1 → v2 → v1 | **Done, then reverted** — v2 hard-caps at 8 groups, v1 doesn't | none | none | Real regression found live; now back to known-good v1 |
| 2 | `scheduler/get` | v2 → v3 | Not started | `properties` (read-only) — though v2 may already have this, see regression note | new `foxess_device_capabilities_json` setting | Low — read-only |
| 3 | `scheduler/enable` | v1 → v3 | Not started, and **blocked pending a re-test of the group-count limit against v3 specifically** | none used, but shape changes (`enable` removed, per-device field filtering) | none new | High — write path, young API version, unresolved group-count question |

Unaffected throughout: `scheduler/get\|set/flag` (v1), `device/real/query` (v1),
`device/report/query` (v0).
