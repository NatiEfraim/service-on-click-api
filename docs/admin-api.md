# Admin / approver / super-admin API - frontend guide

Everything a frontend needs to build the management screens of Service On Click: who may call what, what each call returns, the rules the server
enforces (so the UI can mirror them) and how to react to every refusal.

- Base URL: `/api/...` (all paths below are relative to it).
- Login, `GET /user`, `POST /logout` are unchanged (ADFS, Passport token in the cookie). Every call below needs the logged-in user.
- Messages that a person reads are Hebrew; the shape of every response is described here in English.
- The database is `srvonclick-db` (MySQL). `php artisan run:all` rebuilds it (`migrate:fresh --seed`) - there is nothing else to run or seed for
  the management layer (see section 12 for what changed in the migrations).

## 1. Roles and what each can reach

The role names are Spatie roles. **What a user may do inside a booking type comes from the permissions `{role}_{booking_type_id}`** (e.g.
`approver_8`), exactly as before; the server checks those on every call. The route group only decides who is allowed in at all.

| Role | Hebrew | Route groups | Acts on bookings? |
|---|---|---|---|
| `super_admin` | מנהל מערכת | all three below | **yes, everywhere** - acts as the admin of every booking type, in every unit (no per-type permission needed) |
| `admin` | מפקד מחנה | `bookings-approver-management`, `bookings-admin-management` | **yes, every turn** - on the booking types it holds `admin_{type}` for, in its own unit: acts as control officer, vice approver and approver, and manages their roles |
| `approver` | מפקד תחום | `bookings-approver-management` | yes - the final decision |
| `vice_approver` | גורם מטפל | `bookings-approver-management` | yes - the first decision |
| `transportation_control_officer` | רמ"ד תו"ב | `bookings-approver-management` | yes - only where that role is linked to the booking type (not seeded today) |
| `user` | משתמש | none of these | no |

Route groups (prefixes under `/api`):

- `bookings-approver-management` - every manager role.
- `bookings-admin-management` - `admin` and `super_admin`.
- `bookings-super-admin-management` - `super_admin` only.

A user who is not allowed in gets **403**. Inside a group the server narrows further by the user's **unit** and **booking types**; a booking of
another unit or type behaves as if it did not exist (**404**).

### Turn-based route (important for the buttons)

A booking moves through the roles in turn, and only the role whose turn it is may act (the admin of the type holds every turn):

```
received ──vice approver──▶ 15 / 16 ──approver──▶ approved(3) / rejected(4)
                                   approved(3) ──approver──▶ completed(5) / canceled by approver(7)
(barber)  approved(3) ──vice approver "barber done"──▶ 11 ──approver──▶ 5 / 7
(transportation, when a control officer exists) received ──control officer──▶ 17 / 18 ──vice approver──▶ ...
```

- The **admin of the type (and the super admin) holds every turn**: at each stage they get the same buttons as the role whose turn it is, so
  `actions` is never empty for them on an open booking, and `can_edit_values` follows the same rule. They still go through the route (no jumping
  from "received" to the final approval), cannot set the requester's own cancellation, and a closed booking has no actions for anyone. Their
  name goes on the timeline and in the audit log as the one who acted. The **approver waits** until the vice approver has passed the booking on.
- A **vice approver / control officer sees only the bookings at their own stage.** Anything else is a 404 for them. This is decided **per booking
  type**: someone who is vice approver of type 1 and approver of type 2 sees only their own stage in type 1 and everything in type 2.
- The requester's own cancellation (status 6) is never done here.
- **Do not hard-code this.** Render the buttons from `actions` in the booking list/detail (section 4); the server applies the same rule when the
  button is pressed.

## 2. Conventions

### Success
```json
{ "data": { ... }, "message": "optional Hebrew text" }
```
Lists return `{ "data": [ ... ], "meta": { "total", "page", "last_page", "per_page" } }`.

### Errors
| Status | Body | Meaning / what the UI does |
|---|---|---|
| 401 | `{message}` | not logged in - go to login |
| 403 / 404 / 405 | `{"message": "הבקשה לא ניתנת לביצוע."}` | **this one vague text** (the system never says whether a record exists or why it refuses). Show it as is. The only exception is `POST|PUT .../bookings` (405), whose message says that bookings are opened in the app. |
| 409 | specific Hebrew message | the thing changed under you (stale `version`) or a sync conflict is open. **Reload the record and let the user retry.** The version is checked *before* "not your turn": a booking that moved on while the screen was open answers 409, not 403 / 404. |
| 422 | specific Hebrew message, or Laravel `{message, errors}` | invalid input - show the message |
| 500 | generic | show a generic error |

`422` from a business rule is `{ "message": "..." }`; `422` from field validation is the usual `{ "message": "...", "errors": { "field": ["..."] } }`.

### Optimistic locking: `version`
Every booking has an integer `version` (starts at 1, +1 on every status change, value correction or sync decision). **Send back the version you
displayed** with every change. If the booking moved on you get **409** - reload and retry.

### Idempotency
`POST .../bookings/{serial}/status` accepts an optional header `Idempotency-Key: <unique string, max 80>`. A double click or a network retry with the
same key returns the first answer instead of acting twice. Use a fresh key (e.g. a UUID) per user action; the same key with a different request is
a 422.

### Identifying a booking
Management endpoints use the booking's **`serial_number`** (the number the mobile app shows). The older `GET /bookings/{uuid}` still exists.

## 3. Start-up: who am I, what can I see

### `GET /bookings-approver-management/me`
```json
{ "data": {
  "user": { "uuid": "...", "personal_number": "1000002", "personal_id": null, "full_name": "...", "unit_id": 1, "unit_name": "H", "department_id": null, "department_name": null },
  "roles": ["admin"],
  "permissions": ["admin_1", "admin_2"],
  "capabilities": { "audit.view": false, "sync.view": false, "sync.operate": false, "sla.manage": false, "config.global": false,
                    "users.manage": true, "dashboard.view": true, "reports.view": true, "targets.manage": true },
  "is_super_admin": false, "is_admin": true, "is_handler_only": false,
  "managed_booking_types": [1, 2], "dashboard_booking_types": [1, 2]
} }
```
Use `capabilities` / `is_*` to decide which menu items to show: *Home tiles* (`is_admin` or `is_super_admin`), *Dashboard* (`dashboard.view`),
*Reports* (`reports.view`), *Targets* (`targets.manage`), *Users* (`users.manage`), *Organisation / Audit / Sync / Field settings*
(`config.global`, `audit.view`, `sync.view`, `sync.operate` - all super admin only), *Bookings queue* (any manager).
`is_handler_only` = a vice approver / control officer on **every** type they manage: show only the queue, no dashboards or settings.

### `GET /bookings-approver-management/catalog`
Lists for the dropdowns: `statuses` (`id`, `name`, `display_name`), `stages`, `roles`, `booking_types`, `booking_type_statuses`
(`{ "1": [1, 15, 16, ...] }` - which statuses each type can reach), `groups`, `units` (`code` = the unit's letter, `name` = the name people see; a
non-super-admin gets only their own unit), `departments`, `action_types`.

## 4. Bookings (all manager roles)

### `GET /bookings-approver-management/bookings`
The bookings the user may see. Query (all optional):

| Param | Meaning |
|---|---|
| `q` | serial number (substring), ID number / personal number (prefix) or name |
| `statuses`, `types`, `units` | comma separated ids |
| `stage` | `control_officer`, `vice_approver`, `approver`, `supplier_files`, `supplier_approval`, `supplier_number`, `realization`, `barber_done`, `closed` |
| `from`, `to` | `Y-m-d`, the day the booking was opened |
| `open_only=1` | hide closed bookings |
| `ids` | comma separated serial numbers |
| `handler` | user uuid of someone who moved the booking |
| `mine=1` | only bookings the user has an action on right now |
| `overdue=1` | only bookings older than their type's target |
| `sync` | `conflict`, `pending` or `synced` |
| `sort`, `dir` | `serial_number`, `status_changed_at` (default), `created_at`, `status_id`, `booking_type_id`, `stage_age_hours`; `asc` / `desc` |
| `page`, `per_page` | default 25, max 100 |

One row:
```json
{
  "uuid": "...", "serial_number": 9001, "booking_type_id": 1, "booking_type_label": "רכב שיתופי", "unit_id": 1,
  "owner_uuid": "...", "owner_name": "...", "owner_personal_id": "123456789",
  "status_id": 15, "status_label": "...",
  "stage": "approver", "stage_label": "ממתין להחלטת מפקד תחום",
  "stage_age_hours": 3.0, "total_age_hours": 10.0, "target_hours": 8.0, "overdue": true,
  "status_source": "external", "version": 1,
  "opened_at": "2026-11-01T08:00:00+00:00", "status_changed_at": "...",
  "sync_state": "synced", "has_conflict": false,
  "actions": [ { "status_id": 3, "label": "אישור סופי", "comment_required": false },
               { "status_id": 4, "label": "דחייה סופית", "comment_required": true } ]
}
```
- `actions` = the buttons for this user right now (empty = view only). `comment_required` = the user must type a comment first.
- `sync_state`: `pending` = a decision made here is not yet sent to the mobile app; `conflict` = needs a super admin (section 9); `synced`.
- `target_hours` is the handling target of the booking type (section 6); `overdue` compares it with `total_age_hours`.

### `GET /bookings-approver-management/bookings/{serial}`
Everything of the row above plus:

| Field | Meaning |
|---|---|
| `booking_owner`, `vice_approver` | people with phone numbers |
| `booking_values` | the formatted values, **same shape as `get-bookings`** (each value has `editable`, `form_field_id`, `field_type`, `value`) |
| `comments` | `{ id, comment, created_at, user }` |
| `history` | the timeline: `{ action_label, status_id, status_label, actor_name, actor_id, source, occurred_at }` (`source`: `external` mobile app, `internal` a person here, `system`) |
| `can_edit_values`, `editable_fields` | whether the user may correct values now, and which: `[{ form_field_id, field_display_name, field_type_id }]` (date = 3, time = 4) |
| `sync` | `{ pending_export, ext_status_id, ext_status_label, open_conflict }` |
| `attachments` | the booking's files and images: `[{ kind: "file"\|"image", id, name, mime, inline, available, url }]` (see below) |
| `audit` | the audit rows of this booking, **super admin only** (otherwise `null`) |

The list rows carry `attachments_count` (files + images) for a paper-clip icon.

### `GET /bookings-approver-management/bookings/{serial}/attachments/{kind}/{id}`
The bytes of one file or image (`kind` = `file` | `image`, `id` from `attachments[]`). Use the `url` of the entry as it is: **it needs the same login as
every other call**, so load it with `fetch` + the cookie / token (an `<img src>` works when the cookie is sent), not from the bucket.

- The booking json only *names* the files (`SrvOnClickFiles_<stored name>`, no extension) and their bytes arrive by their own way into one bucket
  folder (`ATTACHMENTS_FOLDER_NAME`, default `import-files`). Until they arrive an entry has `available: false`, `url: null` - show "not received yet".
- Served only to someone who may see the booking (one vague **404** for everything else, including "no such file"), and only these types:
  JPEG, PNG, WebP, GIF and PDF (`inline: true` - show in the page; `Content-Disposition: inline`) and DOCX / XLSX / PPTX (`inline: false` - offer a
  download). Any other extension is never read. Responses carry `X-Content-Type-Options: nosniff`.

### `POST /bookings-approver-management/bookings/{serial}/status`
```json
{ "status_id": 3, "version": 2, "comment": "חסר מידע" }
```
Header `Idempotency-Key` (optional). Returns `{ "data": { serial_number, status_id, status_label, version, correlation_id, sync_state: "pending" }, "message": "..." }`.

Refusals: **403** not your turn / not allowed; **404** not visible; **409** stale `version` or an open sync conflict; **422** comment rules (required for
reject / cancel; Hebrew letters and spaces only, max 100) or the food cancellation deadline (cancel at least N business days before the supply date).
After a success update the row with the returned `version`.

### `PUT /bookings-approver-management/bookings/{serial}/values`
Correct the date / time of a booking while it is the user's turn (before the final approval).
```json
{ "values": { "2": "2026-12-05", "3": "11:30" }, "version": 2, "reason": "תיאום מחדש" }
```
Keys are `form_field_id` from `editable_fields`. Date `Y-m-d` (not in the past, not a blocked date), time `HH:MM`. A barber booking's slot moves with the
values and must be free (inside service hours, outside the break, not taken). Returns `{ version, changed: ["label", ...] }`.

### `POST|PUT /bookings-approver-management/bookings`, `POST /bookings-approver-management/bookings/{bookingTypeId}/create`
Always **405** (and audited): bookings are opened in the mobile app only. Do not build a "new booking" button.

## 5. Dashboards and reports

| Call | Who | Returns |
|---|---|---|
| `GET /bookings-approver-management/overview` | any manager | the user's own dashboard: `open_total` (a number), `awaiting_me`, `overdue`, `needs_decision`, `stale` (each `{count, serials}`), `by_stage`, `by_type`, `by_type_stage`, `by_status`, `my_top`, `oldest_top`, `bottlenecks` (top 4), `trend` (30 days), `definitions` |
| `GET /bookings-admin-management/home` | admin, super admin | main screen: `domains[]` = a tile per booking type `{ booking_type_id, name, icon, group, open, total, target_hours, overdue }`, and `by_type_stage` |
| `GET /bookings-approver-management/domain-dashboard?types=1,2&days=30` | approver, admin, super admin | `totals`, `handling` (median / p90 / avg hours), `by_stage`, `stages` (bottleneck analysis), `rates`, `oldest`, `overdue_serials`, `trend`, `target` |
| `GET /bookings-approver-management/bottlenecks?days=30&types=1,2&unit=1` | any manager (`unit` only for super admin) | `stages[]` (open count, median / p90 time, severity `critical | warning | ok | no_target`, top handlers), `by_type`, `handlers`, `pools` (open bookings per role holder), `rates` |
| `GET /bookings-admin-management/reports?...` | admin, super admin | `{ rows, total, truncated }` - flat rows; build the Excel / PDF in the browser |

`reports` filters: `serial`, `types`, `groups`, `statuses`, `units`, `departments` (comma separated ids), `requester`, `personal_id`,
`opened_from`, `opened_to`, `updated_from`, `updated_to` (`Y-m-d`), `stage`, `overdue` (`yes|no`), `min_age_days`. All filters are applied by the
database; `total` is the number of matches and at most 5000 rows are returned (`truncated`).

The stage timings come from the booking timeline (`application_logs`). Bookings imported before this version have no stage history, so their
stage-duration statistics stay empty; their open age is still correct.

## 6. Handling targets (admin, super admin)

A booking type has one target: the total hours from the booking's opening to its closing.

- `GET /bookings-admin-management/targets` -> `[{ booking_type_id, target_hours, warn_ratio, set_by }]` (the types the user manages)
- `PUT /bookings-admin-management/targets` `{ "booking_type_id": 1, "target_hours": 48, "warn_ratio": 0.8 }`. `target_hours: null` removes the target.
  Range 1 hour .. 180 days; `warn_ratio` strictly between 0 and 1. An admin may set only the types they administer.

## 7. Settings of a booking type

### `GET /bookings-approver-management/settings/{unitId}/{bookingTypeId}`
For whoever manages the type in that unit (a super admin: any). Returns `system_references` (with the decoded `value`), `form_fields`, `field_types`,
`options` (a flat list with `parent_id`), `statement`, `managers` (`{uuid, full_name, personal_number, roles[]}`), `open_count`, `can_edit_global`.

- `form_fields` are the fields **in the form** (archived ones are not listed), in the order the form shows them (`position`, then `id`). A field:
  `{ id, parent_id, field_name, field_display_name, field_type_id, is_required, editable, position, version, used_by, is_custom, can_change_type, can_remove }`.
  `used_by` = how many bookings hold a value; `is_custom` = made in this system (`field_name` starts with `int_`) as opposed to one of the mobile app's;
  `can_change_type` / `can_remove` tell the UI what to offer (section 7b). A field with a `parent_id` sits inside another field - show it under its parent.
- `field_types` = what a new field may be: `[{ id, name, display_name, has_options, can_be_editable }]` - `has_options` -> ask for the options,
  `can_be_editable` -> offer the "an approver may correct it" switch.
- `options` lists only the options **in the form**: an option that bookings chose and was removed is archived (see below) and not listed.

The values themselves are still saved with the existing endpoints: `PUT /bookings-approver-management/system-references`
(body `booking_type_id` + `details_values`), the super-admin `update-options`, `update-select-option`, `update-option`, `remove-*`,
`update-statement`. Every one of them is now audited (`config.system_reference.update`, `config.options.update|remove`,
`config.statement.update`, with the values before and after). `PUT system-references` now also requires a managing role **on the booking type in
the body** (403 otherwise, audited): holding a role on one type no longer opens the settings of another.

### `POST /bookings-approver-management/settings/references/{id}/impact`
Before saving a list of blocked dates, ask what it would cancel (nothing is saved):
```json
{ "dates": ["2026-12-01", "2026-12-02"] }
```
-> `{ new_dates, removed_dates, affected_count, affected_serials, affected_in_other_units, cancel_status_id, cancel_status_label }`. Show a confirmation when
`affected_count > 0`, then save with the existing PUT. **The list of blocked dates belongs to the booking type, not to a unit**: a new date cancels
the open bookings of *every* unit on it. `affected_count` is that whole number; `affected_serials` lists the serial numbers of the user's own unit (the
super admin: all), `affected_in_other_units` is how many more there are. Saving cancels the affected bookings (status 7, barber: 13) as a normal
decision: it is on their timeline, audited and sent back to the mobile app.

### 7b. The form of a booking type - `bookings-super-admin-management/form-fields...` (super admin)
Add, rename, change, move and remove the fields of the form. Every change is checked, versioned, written to the audit log
(`config.form_field.*`) and **queued for the mobile app** as a `system_config` file (`docs/mobile-sync-contract.md`) in the same transaction - a
refused change leaves nothing behind. The two systems have different ids, so a field is known to both by its **`field_name`**: the mobile app's fields keep
theirs, a field made here gets `int_<12 characters>`.

| Call | Body | Returns |
|---|---|---|
| `POST /form-fields/{unitId}/{bookingTypeId}` | `{ field_display_name, field_type_id, is_required?, editable?, options?: ["..."] }` | **201** the field (`id, field_name, ..., version, is_custom, options[]`), placed at the end |
| `PUT /form-fields/{id}` | `{ version, field_display_name?, field_type_id?, is_required?, editable? }` - only what changes | the field with its new `version` (unchanged -> same `version`, nothing sent) |
| `PUT /form-fields/{unitId}/{bookingTypeId}/order` | `{ ids: [..] }` - the ids of **all** the stand-alone fields (`parent_id: null`) of the form, in the new order | `{ saved: true, order: [field_name..] }` |
| `DELETE /form-fields/{id}?version=` | (`version` optional) | `{ mode: "delete" \| "archive", used_by }` |
| `PUT /form-fields/{id}/editable` | `{ editable, version }` | `{ id, editable, version }` |

Rules (the messages are in Hebrew and safe to show):
- **Name**: 2-50 characters, letters / digits / basic punctuation, not the name of another field of the form (**422**). **Type**: not password, hidden,
  colour, range, month, week or number-with-asterisk. A choice (select, radio, checkbox, multi-select) needs at least one option and none twice.
  Only a date or a time can be `editable`.
- **Changing the type**: only on a field made here, only while no booking holds a value for it, and a choice stays a choice (a plain field a plain
  field) - otherwise **422** with the reason. **A field of the mobile app can be renamed, made required or not, and moved - but never retyped or
  removed** (its code, and this system's, find it by name). Only what a request *changes* is checked.
- **Removing** never deletes what bookings hold: a field with values is **archived** (`mode: "archive"` - out of the form and the settings, the
  values stay on the bookings and still show on the booking), one nobody filled is deleted. Refused: the last field of a form (**409**), a field of
  the mobile app or one inside another field (**422**), a field a system setting or a filter depends on (**409**). An archived field gets **404** on
  later calls.
- **Stale `version`** -> **409** ("changed meanwhile"): reload the settings and retry. A reorder list that is not exactly the stand-alone fields of the
  form as it is now -> **409**.
- **Not a super admin** -> **403**; a unit that does not offer the type -> **404**.
- The **names of a unit or a department may not appear in any text** that goes to the mobile app (field names, options, statements, settings):
  **422** "the text holds an internal name of a unit or a department" - use the unit's letter only.

**Options.** The existing option endpoints (`update-options`, `update-select-option`, `update-option`, `remove-options`, `remove-option`) work as
before, with two differences: an option that any booking **chose** is archived instead of deleted when it is removed (deleting it used to delete that
booking's value with it), and every change is queued for the mobile app (`option_field` changes, named by the path of option names).

## 8. People, organisation (admin / super admin)

- **Roles:** unchanged endpoints `POST /bookings-admin-management/assign-approver-role` and `POST /bookings-super-admin-management/assign-admin-role`
  (`get-approvers`, `get-admins`, `get-*-permissions`). New: optional `reason` (max 500) is stored in the audit log with the before / after
  permission lists. **An admin may now grant only on the booking types they administer and only to people of their own unit, and never to
  themselves (403 otherwise).**
- **Users:** `GET /users/{identifier?}` and `POST /users` as before. Responses now also carry `unit_id`, `unit_name`, `department_id`,
  `department_name`; `POST /users` takes an optional `department_id` (an active department of the unit). **An admin sees only the people of their
  own unit** in `GET /users`, `get-approvers` and `get-approver-permissions`; the super admin sees everybody.
- `PUT /bookings-admin-management/users/{uuid}/department` `{ "department_id": 3 }` (or `null`). An admin: own unit only; the department must be
  an active one of the person's unit (422).
- **Organisation (super admin):**
  - `GET /bookings-super-admin-management/org` -> units with `code`, `name`, `users` and their `departments` (`id`, `name`, `is_active`, `users`).
  - `POST /org/units` `{ "code": "K", "name": "כנף צפון" }` - `code` is 1-3 English letters, unique. **A unit's letter never changes** (the mobile app knows
    it); `PUT /org/units/{id}` renames only (send the same `code`).
  - `POST /org/units/{unitId}/departments` `{ "name": "לוגיסטיקה" }`; `PUT /org/departments/{id}` `{ "name"?, "is_active"? }` - a department with
    people in it cannot be switched off (409).

## 9. Audit log and sync (super admin)

### Audit - `GET /bookings-super-admin-management/audit`
Newest first, 40 per page. Filters: `serial`, `actor` (personal number), `action` (prefix such as `booking.`, `config.`, `user.`), `source`
(`internal|external|automation`), `type` (booking type id), `result` (`success|denied|failed`), `from`, `to` (`Y-m-d`), `page`. A row:
`{ id, occurred_at, actor_type, actor_id, actor_name, source, action, entity_type, entity_id, booking_serial, unit_id, booking_type_id, before, after, reason, result, correlation_id }`.

- `GET /audit/verify` -> `{ valid, checked, broken_at }`: checks the tamper-evident hash chain. Show a red banner when `valid` is false.
- `PUT|PATCH|DELETE /audit/{id}` -> always 405: the log is append-only (the attempt is itself logged).
- Actions you will see: `booking.status_change`, `booking.values.update`, `booking.canceled_by_restriction`, `booking.create` (denied),
  `config.sla_target.set|remove`, `config.form_field.create|update|reorder|archive|delete|editable`, `config.system_reference.update`,
  `config.options.update|remove`, `config.statement.update`, `user.create`, `user.permissions.change`, `user.department.change`, `org.unit.*`,
  `org.department.*`, `sync.conflict.*` (`detected`, `resolve` by a person, `auto_resolve` by the import), `sync.outbound.manual_retry`,
  `sync.outbound.blocked` (a file held back because it held an internal name), `audit.modify` (denied).
  The automatic transfer events of the schedule (`sync.outbound.sent|failed`) are stored but not listed.

### Sync with the mobile app - `bookings-super-admin-management/sync...`
A decision made here is saved at once and **sent to the mobile app by a scheduled job** (every 2 minutes) as a file in the bucket.

- `GET /sync` -> `{ health, files[], conflicts[], pending_decisions[], can_operate }`. `health`: `export_enabled`, `config_channel_enabled`, `last_sent_at`,
  `pending_decisions`, `queued_value_files`, `queued_config_files`, `blocked_files`, `failed_files`, `open_conflicts`. A file's `kind` is `booking_status`,
  `booking_values` or `system_config`; its `state` is `queued`, `sent`, `failed`, `superseded`, `blocked` or `awaiting_external_support`.
- `GET /sync/files/{id}` -> the file and the rows it carries. `POST /sync/files/{id}/retry` sends a failed or blocked file again. `POST /sync/run` sends now.
- **`system_config` files** carry the changes of the configuration (form structure, options, statements, settings, a new unit) - one file per request,
  sent **oldest first, and the round stops at the first file that cannot go** (failed and waiting, or blocked), so the mobile app never gets a later
  change before an earlier one. **`blocked`**: the file holds the name of a unit or a department (a unit was given that name after the change was
  queued) - it is not sent, the audit log says so once, and the changes after it wait; change the name (or `retry`) and it goes out.
  `awaiting_external_support`: `SYNC_CONFIG_CHANNEL_ENABLED=false` - the changes are kept and go out when it is switched on.
- **Conflict**: the mobile app changed a booking's status while a decision made here was still waiting. The booking shows `sync_state: "conflict"`,
  every change to it is refused with 409, and the decision is held back until a super admin settles it:
  `POST /sync/conflicts/{id}/resolve` `{ "resolution": "accept_external" | "keep_internal", "reason": "..." }` (reason required).
  `keep_internal` is refused (422) when the mobile app status is the requester's own cancellation.
- What needs **no** decision: when the mobile app shows the status that was decided here, the requester cancels (the cancellation always stands), or a
  booking approved here is carried on to completion there, the import settles it by itself (the conflict, if one was open, closes with
  `resolution` `confirmed_by_mobile` / `superseded_by_mobile`, and the audit log gets `sync.conflict.auto_resolve`).
- Files: the decisions go out as **one cumulative snapshot per day** (`SrvOnClick_r2b_booking_status_<Y-m-d>.json`): a decision the mobile app has
  not confirmed is written again into each new day's file. A corrected value goes out as its own file (`SrvOnClick_r2b_booking_values_<time>_<id>.json`),
  in order, per booking.

## 10. What changed in endpoints that already existed

| Endpoint | Change |
|---|---|
| `get-bookings/{type}` -> `next_actions` | now follows the turn-based rule above (an admin gets none; an approver gets none before the vice approver acted). Prefer `actions` of the new list. |
| booking values -> `editable` flag | now true only for date / time fields the user may correct **now** (it used to be "status below 3"). |
| `PUT .../system-references` | blocking a date cancels the bookings on it through the normal decision path (timeline, audit, sent back). Use `.../impact` first. Requires a managing role on the booking type in the body (403 otherwise); audited. |
| `PUT .../update-options`, `update-select-option`, `update-option`, `update-statement`; `DELETE .../remove-options`, `remove-option` | now audited, with the values before and after, and queued for the mobile app (`system_config`). **Removing an option that bookings chose archives it** (it used to delete the bookings' values with it); a text that holds a unit or department name is refused (422). |
| `GET .../settings/{unit}/{type}`, `get-select-options`, `get-options`, `get-siblings-options`, `index-selects` | list only what is in the form: archived fields and options are left out. Fields come in `position` order. |
| booking detail / list | detail gains `attachments`, list rows `attachments_count`; files and images are imported from the json and served by `.../attachments/{kind}/{id}` (they used to be ignored). |
| `assign-*-role` | optional `reason`; audited; an admin is limited to their own types and unit, and cannot change their own roles. |
| `GET /users`, `get-approvers`, `get-approver-permissions` | an admin gets only the people of their own unit. |
| `GET /user`, `GET /users`, `POST /users` | extra `unit_id`, `unit_name`, `department_id`, `department_name`; `POST /users` accepts `department_id`. |

## 11. Suggested screens

1. **Bootstrap** - `me` + `catalog`; build the menu from `capabilities`.
2. **Home** (admin) - `home` tiles; click a tile -> bookings queue filtered by `types`.
3. **Queue** - `bookings` with `mine=1` as a tab; columns stage, age (red when `overdue`), owner, sync state; row actions from `actions`.
4. **Booking drawer** - `bookings/{serial}`: values (pencil only where `editable`), timeline, comments, **files and images** (`attachments`: thumbnails for
   `inline` entries, a download link for the rest, "not received yet" when `available` is false), action buttons, version kept in state.
5. **Dashboard** - `overview` (everyone) and `domain-dashboard` / `bottlenecks` (leaders).
6. **Settings per domain** - `settings/{unit}/{type}` + the existing save endpoints + targets + `impact` confirmation. A **form structure** tab
   (super admin): the fields in order (drag to reorder -> `order`), "add a field" from `field_types`, edit / remove only where `can_remove` /
   `can_change_type` allow, a confirmation that says "archived, the bookings keep their values" when `used_by > 0`.
7. **Users and roles** - existing lists + `assign-*-role` + department selector.
8. **Organisation** (super admin) - `org`.
9. **Reports** (admin) - `reports` + client-side export.
10. **Audit** and **Sync** (super admin).

## 12. Jobs and configuration (for whoever deploys)

- **Database.** While the project is in local development the management columns live in the *original* create-table migrations
  (`bookings`: `version`, `ext_status_id`, `status_source`, `status_changed_at`; `application_logs`: `status_id`, `source`, `correlation_id`;
  `units`: `internal_name`; `users`: `department_id`; `form_fields`: `version`, `position`, `is_active`; `option_fields`: `is_active`), and
  `departments` is created together with `units`. The new tables
  are the four `2026_11_01_1000xx` migrations (`sla_targets`, `audit_log`, `idempotency_keys`, the sync tables). So the database has to be built
  fresh - `php artisan run:all` / `migrate:fresh --seed`; `migrate` alone on a database made before this change does not add the columns. Once a
  shared database exists, change it with new migrations again.
- `php artisan schedule:work` (or the system cron) runs: `importBookingJson:mysql` / `importApplicationLogJson:mysql` every 5 min (existing),
  **`exportBookingStatusJson:mysql` every 2 min** (sends decisions and corrected values), `audit:verify` daily 02:30, idempotency keys pruned daily.
  The export writes files itself - no queue worker is needed for it.
- Bucket folders: reads `import-json/...` (unchanged); **writes `export-json/booking-status/`, `export-json/booking-values/` and
  `export-json/system-config/`** (`EXPORT_JSON`, `BOOKING_STATUS_JSON_FOLDER_NAME`, `BOOKING_VALUES_JSON_FOLDER_NAME`, `SYSTEM_CONFIG_JSON_FOLDER_NAME`);
  **reads the bytes of files and images from `import-files/`** (`ATTACHMENTS_FOLDER_NAME`; `export-files` is the mobile app's own backup folder, for a
  shared bucket). `SYNC_CONFIG_CHANNEL_ENABLED=false` keeps the configuration changes in the database instead of writing them.
- The exact file formats, both ways, and what the mobile side has to do: `docs/mobile-sync-contract.md`.
- `EXPORT_JSON_ENABLED=false` keeps decisions waiting in the database (nothing is written). `EXPORT_BOOKING_STATUS_PREFIX` sets the file-name prefix the
  mobile importer really reads (`SrvOnClick_r2b_booking_status_` by default; its configuration and importer disagree). `EXPORT_JSON_TIMEZONE`
  (e.g. `Asia/Jerusalem`) is the timezone the mobile importer uses to name "today's" file - this app runs in UTC, so if the mobile side counts the
  day in Israeli time, set it, or decisions made in the first hours after local midnight go into a file it no longer reads. `EXPORT_JSON_CRON`,
  `EXPORT_JSON_MAX_ATTEMPTS` tune the schedule and retries.
- Control officer: the stage exists only for booking types that have the `transportation_control_officer` role linked in `booking_type_has_roles`.
  Nothing is linked by default, so every booking starts at the vice approver. Link the role (and seed `transportation_control_officer_{id}`
  permissions) to switch the stage on for a type.
