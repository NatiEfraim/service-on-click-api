# The files between the mobile app and this system

Two systems, two databases, no shared tables: everything travels as JSON files in the bucket. **b2r** = from the mobile app to this system,
**r2b** = from this system back to the mobile app. This page is for whoever works on the **mobile side** (`service-on-click-mobile-api`): what this
system reads, what it writes, and what the mobile side has to do for each file. (The endpoints for the front end are in `admin-api.md`.)

| File | Direction | Folder in the bucket | Written | Read by |
|---|---|---|---|---|
| `SrvOnClick_b2r_export_bookings_<date>.json` | b2r | `import-json/booking-data/` | the mobile app | this system, every 5 min |
| `SrvOnClick_b2r_application_logs_<date>.json` | b2r | `import-json/application-log/` | the mobile app | this system, every 5 min |
| `SrvOnClickFiles_<stored name>.<ext>` (the bytes of a file / image) | b2r | `import-files/` (`ATTACHMENTS_FOLDER_NAME`) | the mobile app / Hatch | this system, when somebody opens the booking |
| `SrvOnClick_r2b_booking_status_<Y-m-d>.json` | r2b | `export-json/booking-status/` | this system, every 2 min | the mobile app (existing importer) |
| `SrvOnClick_r2b_booking_values_<time>_<id>.json` | r2b | `export-json/booking-values/` | this system | **the mobile app needs an importer** |
| `SrvOnClick_r2b_system_config_<time>_<sequence>.json` | r2b | `export-json/system-config/` | this system | **the mobile app needs an importer** |

A file that was read moves to `loaded-json/`. Reading a file twice changes nothing (bookings are found by `serial_number`).

## b2r: what this system reads

### Bookings
As the mobile export writes them today (`booking_type_id`, `booking_owner_personal_id`, `serial_number`, `status_id`, `created_at`, `updated_at`
as unix times, `booking_values[]`, `comments[]`, `files[]`, `images[]`). A booking exported before comes back as a *change record*
(`booking_type_id: null`, only the news). Things worth knowing:

- **Files and images.** `files: [{ "file_id": 7, "path": "SrvOnClickFiles_6adf010810404_j0iNc8FqHf" }]` and `images: [{ "image_id": 3, "path": "..." }]`.
  The `path` has **no extension**; this system links the booking to the name and looks for `<path>.<ext>` in `import-files/` when somebody opens the
  booking (jpg, jpeg, png, webp, gif, pdf, docx, xlsx, pptx - anything else is never served). The bytes may arrive before or after the json. The
  mobile app's own backup folder is `export-files/`: when the two systems share a bucket, `ATTACHMENTS_FOLDER_NAME=export-files` reads it as it is.
- **Fields made in this system.** The ids of `form_fields` / `option_fields` are the mobile app's. A field made in the web (`field_name`
  `int_<12 characters>`, see below) gets an id of its own in the mobile database, so a value node for it must **also carry the field's name**:
  ```json
  { "field_id": 95, "field_name": "int_3f2a9c1d0b7e", "field_display_name": "...", "field_type_id": 5, "value": null,
    "option_fields": { "option_id": 410, "display_name": "ליד החלון", "form_field_id": 95, "field_type_id": 2, "parent_option_id": null, "value": null } }
  ```
  This system then finds the field by `field_name` and each option by its `display_name`. Without `field_name` such a node is skipped (and counted in
  `values_skipped`), exactly as an unknown field was before.

### Application logs
Unchanged: `{ serial_number, status_id, action_type_id, personal_id, created_at }`.

## r2b: what this system writes

All files are `{ ... }` JSON in UTF-8, written to the bucket; a file that cannot be written is tried again with a growing wait, and a later file never
overtakes an earlier one that has not gone (the sync screen shows the state).

### `booking_status` - the decisions made here
`{ "bookings": [ { "serial_number": 123, "status_id": 3, "personal_id": "212209993", "updated_at": "1793257571" } ] }`
One **cumulative snapshot per day** under the day's name (the existing importer reads one file by its exact name): every decision the mobile app has
not shown back yet. `personal_id` = the ID number of whoever decided; `updated_at` = unix seconds. The prefix and the timezone that names "today" are
configurable (`EXPORT_BOOKING_STATUS_PREFIX`, `EXPORT_JSON_TIMEZONE`) because the mobile configuration says `r2b` and its importer looks for `b2r`.

### `booking_values` - a date / time corrected while a booking was handled
```json
{ "changes": [ { "serial_number": 123, "field_id": 9, "value": "2026-12-05", "personal_id": "212209993", "changed_at": 1793257571, "correlation_id": "uuid" } ] }
```
Update `booking_values.value` where `booking_id` = the booking with that serial and `form_field_id` = `field_id` (the mobile app's id: the corrected
fields are date / time fields of the seeded forms). Do **not** echo the change back as a new export (`is_updated_value` stays as it is).

### `system_config` - every change of the configuration
One file per request, `{ "changes": [ ... ] }`, applied **in the order of the file names** (the sequence at the end of the name is the order the changes
were made - sort by name). Every change has `entity`, `op`, `unit_id`, `booking_type_id` (when it has one), `correlation_id`, `changed_at` (unix) and
what it is about. **The ids of the two systems differ, so nothing is addressed by a row id from this system**: a field by `field_key` (its `field_name`),
an option by `option_path` (the names from the top option down), a setting by its name.

| `entity` / `op` | Fields | What to do |
|---|---|---|
| `form_field` / `create` | `field_key`, `data { field_display_name, field_type_id, is_required, editable, position, options[] }` | create the field with `field_name = field_key` (and its options); it gets the mobile app's own id |
| `form_field` / `update` | `field_key`, `data` (only what changed: `field_display_name`, `field_type_id`, `is_required`, `editable`) | update that field of that unit and booking type |
| `form_field` / `reorder` | `order: [field_key, ...]` | `position` = the index, for the fields of that unit and booking type |
| `form_field` / `archive` | `field_key` | hide it from the form (`is_active = false`); **keep the values** of existing bookings |
| `form_field` / `delete` | `field_key` | delete it (nobody filled it) |
| `option_field` / `create` | `field_key`, `option_path`, `data { name, category_name, has_category, field_type_id, is_input, payload_section_id, gender }` | create the option; its name is the last item of `option_path` and its parent is the option the rest of the path names (none = a top option) |
| `option_field` / `update` | `field_key`, `option_path`, `data { name? , parent_path? }` | rename it, and / or move it under `parent_path` (`[]` = to the top) |
| `option_field` / `archive`, `delete` | `field_key`, `option_path` | archive (hide, keep what bookings chose) / delete |
| `system_reference` / `update` | `key` (the reference's name), `data` (its **whole** value; for a list of blocked dates, `dates`) | replace the value; for blocked dates also keep `booking_restriction_dates` in line (`CancelsBookingsOnRestrictionDates`) - the bookings on them were already canceled here and the new statuses sent |
| `statement` / `update` | `data { statement, is_active, show_time }` | upsert the statement of that unit and booking type |
| `unit` / `create` | `data { unit_id, code }` | create the unit with that letter. **A unit's name never leaves this system** - only its letter |

Order inside one file: removals, renames, new items, moves - each path is spelled the way the mobile database has it at that moment.

A starting point for the importer (a service with a transaction per change, a `json_import` log channel, `HatchImportable`-style de-duplication) is in
`structure-project/docs/patches/external-system-config-importer.php` - **that patch uses row ids; use the `field_key` / `option_path` addressing above
instead**, and add `is_active` to `form_fields` and `option_fields`, and `position` to `form_fields`, on the mobile side.

### What never leaves
The display name of a unit, the name of a department, and any reference to a department. A text with such a name is refused when it is saved (422),
and every file is checked again before it is written; a file that holds one is held back (state `blocked`) and the changes after it wait.
