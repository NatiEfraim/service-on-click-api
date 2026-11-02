# The files between the mobile app and this system

Two systems, two databases, no shared tables: everything travels as JSON files in the bucket. **b2r** = from the mobile app to this system,
**r2b** = from this system back to the mobile app. This page is for whoever works on the **mobile side** (`service-on-click-mobile-api`): what this
system reads, what it writes, and what the mobile side has to do for each file. (The endpoints for the front end are in `admin-api.md`.)

| File | Direction | Folder in the bucket | Written | Read by |
|---|---|---|---|---|
| `SrvOnClick_b2r_export_bookings_<date>.json` | b2r | `import-json/` | the mobile app | this system, every 5 min (`importBookingJson:bucket`) |
| `SrvOnClick_b2r_application_logs_<date>.json` | b2r | `import-json/` (the same folder, told apart by the name) | the mobile app | this system, every 5 min (`importApplicationLogJson:bucket`) |
| `SrvOnClickFiles_<stored name>.<ext>` (the bytes of a file / image) | b2r | `import-files/` (`IMPORT_FILES_FOLDER_NAME`) | the mobile app / Hatch | this system, every 5 min (`importFile:bucket`), once the booking that names it is saved |
| `SrvOnClick_r2b_booking_status_<Y-m-d>.json` | r2b | `export-json/booking-status/` | this system, every 2 min | the mobile app (existing importer) |
| `SrvOnClick_r2b_booking_values_<time>_<id>.json` | r2b | `export-json/booking-values/` | this system | **the mobile app needs an importer** |
| `SrvOnClick_r2b_system_config_<time>_<sequence>.json` | r2b | `export-json/system-config/` | this system | **the mobile app needs an importer** |
| `SrvOnClick_r2b_users_<Y-m-d>.json` | r2b | `export-json/users/` (`USERS_JSON_FOLDER_NAME`) | this system, with the digital hatch | **the mobile app needs an importer** |

Only names with the system prefixes are read (`SrvOnClick_` for json, `SrvOnClickFiles_` for files and images); anything else in those folders is left
alone. After a read:

- a **json file** that was saved in the database moves to `loaded-json/` (a backup of what was read);
- a **file or image** that a booking names is copied to `files/` (all files and images together, where the bookings serve them from) and the received
  file moves to `loaded-files/` (a backup, like `loaded-json/`). A received file no booking names yet stays in `import-files/` until its booking is
  saved: the booking json carries the stored name, that is how a file finds its booking.

Reading a file twice changes nothing (bookings are found by `serial_number`; a file delivered again replaces its copy in `files/` and the backup keeps
both). Every folder name is configuration (`config/filesystems.php`, `folder_name`); a mobile side that drops its json in subfolders of `import-json/`
is read by naming them (`BOOKING_DATA_JSON_FOLDER_NAME`, `APPLICATION_LOG_JSON_FOLDER_NAME`).

**The digital hatch (FTP).** When the two systems do not share a bucket, the files cross an FTP instead (`HATCH_ENABLED=true`, see "The FTP" at the
end). The bucket stays the working area on both sides; the FTP only carries `booking_status`, `users`, the two b2r json files and the attachments.

## b2r: what this system reads

### Bookings
As the mobile export writes them today (`booking_type_id`, `booking_owner_personal_id`, `serial_number`, `status_id`, `created_at`, `updated_at`
as unix times, `booking_values[]`, `comments[]`, `files[]`, `images[]`). A booking exported before comes back as a *change record*
(`booking_type_id: null`, only the news). Things worth knowing:

- **Files and images.** `files: [{ "file_id": 7, "path": "SrvOnClickFiles_6adf010810404_j0iNc8FqHf" }]` and `images: [{ "image_id": 3, "path": "..." }]`.
  The `path` has **no extension**; the booking import saves a file / image row under that name, and `importFile` finds `<path>.<ext>` in
  `import-files/`, copies it to `files/` and gives the row that key (jpg, jpeg, png, webp, gif, pdf, docx, xlsx, pptx - anything else is never
  served). The bytes may arrive before or after the json: they wait in `import-files/` until the booking is saved.
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

### `users` - who may do what
```json
{ "users": [ { "personal_id": "206094468", "phone": "0545452493", "unit_id": 1,
               "booking_type_roles": [ { "role_id": 4, "booking_type_ids": [1, 2, 3] }, { "role_id": 5, "booking_type_ids": [8] } ],
               "created_at": "1790151370", "updated_at": "1790151370" } ] }
```
One **snapshot per day** under the day's name, rewritten during the day only when something changed. `personal_id` = the nine-digit ID number (the key
the mobile app knows people by), `role_id` = the `RoleEnum` value both systems share (1 super admin, 2 admin, 3 approver, 4 vice approver, 5 user,
6 transport control officer), `booking_type_ids` = the booking types the person holds that role on, `created_at` / `updated_at` = unix seconds as
strings (a role change moves `updated_at`). A **super admin holds the role on every booking type**. Only people with an ID number, not deleted and with at
least one role are listed: **a person who lost every role is simply missing from the next snapshot**, so treat the file as the full list.

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

## The FTP (digital hatch)

Five scheduler commands of the hatch, all off while `HATCH_ENABLED=false`; the FTP account is `HATCH_FTP_*`, the folders are in `config/services.php`
(`digital-hatch`). Names are never changed on the way: what is in the bucket is what is on the FTP.

Every stream has its **own command, queued job, service and trait**, so one can be run (and tested) without the others, and the command names where
the files come from: **`:bucket`** reads the bucket, **`:ftp`** works with the digital hatch. The commands **queue** their job (a queue worker must run;
tries / back-off / timeout in `config/app.php` `jobs.digital_hatch_send_*` and `jobs.import_job`, a run that failed - FTP down - is tried again);
`--sync` does the work right now in the command and prints what it did; `--force` sends (r2b) or takes (b2r) the same file again.

| Command | Job (service) | Every | From -> to | Files |
|---|---|---|---|---|
| `digital-hatch:send-users` | `SendHatchUsersJob` (`UsersExportService`) | 2 min (odd minutes) | bucket `export-json/users` -> FTP `RED_TO_BLACK_FTP_PATH` (`/R2B/Tikshoret-R2B-Prod/`) | `SrvOnClick_r2b_users_<Y-m-d>.json` |
| `digital-hatch:send-booking-status` | `SendHatchBookingStatusJob` (`BookingStatusSnapshotService`) | 2 min (odd minutes, after the export) | bucket `export-json/booking-status` -> the same FTP folder | `SrvOnClick_r2b_booking_status_<Y-m-d>.json` |
| `importBookingJson:ftp` | `ImportBookingJsonFtpJob` (`DigitalHatchService`) | 5 min (minute 2) | FTP `BLACK_TO_RED_FTP_PATH` (`/Tikshoret-B2R-Prod/`) -> bucket `import-json` -> database -> `loaded-json` | `SrvOnClick_b2r_export_bookings_<Y-m-d_H-i-s>.json` |
| `importApplicationLogJson:ftp` | `ImportApplicationLogJsonFtpJob` (`DigitalHatchService`) | 5 min (minute 3, after the bookings) | the same for the logs: FTP -> bucket `import-json` -> database -> `loaded-json` | `SrvOnClick_b2r_application_logs_<Y-m-d_H-i-s>.json` |
| `importFile:ftp` | `ImportFileFtpJob` (`DigitalHatchService`, `ImportFileService`) | 5 min (minute 4) | FTP `BLACK_TO_RED_FTP_ATTACHMENTS_PATH` (`/DigitalFiles-B2R-Prod/`) -> bucket `import-files` -> database (file / image rows) -> `files` + `loaded-files` | `SrvOnClickFiles_<stored name>.<ext>` (the name the booking json gives, plus the extension) |
| `importBookingJson:bucket` | `ImportBookingJsonBucketJob` (`ImportBookingService`) | 5 min | bucket `import-json` -> database -> `loaded-json` | the files the mobile app drops there (this command was `importBookingJson:mysql`, which still works) |
| `importApplicationLogJson:bucket` | `ImportApplicationLogJsonBucketJob` (`ImportApplicationLogService`) | 5 min (minute 1) | bucket `import-json` -> database -> `loaded-json` | (was `importApplicationLogJson:mysql`, which still works) |
| `importFile:bucket` | `ImportFileBucketJob` (`ImportFileService`) | 5 min (minute 4, after the bookings) | bucket `import-files` -> database (file / image rows) -> `files` + `loaded-files` | `SrvOnClickFiles_<stored name>.<ext>` |

- **Sending** puts only the **newest** file of each stream (both are cumulative snapshots) and sends the same name again when its content changed
  (remembered by sha256 in `digital_hatch_sent_files`; `--force` sends it again anyway). `booking_values` and `system_config`
  files are not sent through the FTP.
- **The two ledgers.** `digital_hatch_sent_files` (model `DigitalHatchSentFile`) has a row for every version of an r2b file - its name, the sha256 of
  its content - per **destination**: `bucket` (written there for the mobile side to read), `ftp`, or `local` (the local test folder), so the same
  content is not sent to a place twice and a file that went to the bucket still goes to the FTP. `digital_hatch_fetched_files` (model
  `DigitalHatchFetchedFile`) has a row for every b2r file taken in, by name, with its **stream** (`export_bookings`, `application_logs`, `attachments`)
  and **source**: `ftp`, `local`, or `bucket` (what the `:bucket` imports saved). Each has its own migration. The ":ftp" imports skip a name that is
  in the fetched ledger; the `:bucket` imports only add to it (a file put in `import-json/` again is read again).
- **Booking status = the `bookings.is_status_exported` flag.** `changeStatus` sets the new `status_id` and `is_status_exported = 0` together (the
  decision waits); `digital-hatch:send-booking-status` puts every waiting booking in the day's file (`serial_number`, `status_id`, `personal_id` = who
  decided here, `updated_at`), sends it, and only then sets `is_status_exported = 1`. A failed send leaves them at 0 for the next run. The day's file
  keeps what earlier runs of the day put in it (the mobile importer reads one file per day by its exact name) and a booking decided again replaces its
  row. A booking with an open sync conflict waits until a person settles it. While `HATCH_ENABLED=true`, `exportBookingStatusJson:mysql` leaves the
  status to this command (one sender per flag); its `booking_values` and `system_config` files are unaffected.
- **Local testing** (leave all three empty on dev and prod, where the FTP folders are used): `DIGITAL_HATCH_EXPORT_PATH=C:/some/folder` makes the two send
  commands write their files straight into that folder instead of the FTP; `DIGITAL_HATCH_IMPORT_PATH` (the json files, e.g. `.../tikshort-ftp/b2r`)
  and `DIGITAL_HATCH_IMPORT_FILES_PATH` (the files and images, e.g. `.../tikshort-ftp/b2r_files`) make the three `:ftp` imports read those folders
  instead of the FTP. The import folders must exist (a mistyped path is an error, not a new empty folder) and are never written to or cleaned.
  Use forward slashes in `.env`.
- **A `:ftp` import = taking, then the `:bucket` import.** It first takes the new files into the bucket folder the matching `:bucket` command reads
  (`import-json` for the json, `import-files` for the files and images), then runs that import, so a json file ends up in the database and in
  `loaded-json/` (the file is not changed on the way; a name already in `loaded-json/` gets the date and time added) and a file in the database, in
  `files/` and in `loaded-files/`. A file is taken once: it is in `digital_hatch_fetched_files` from then on; `--force` takes it again, and since
  bookings are found by `serial_number` the second read changes nothing. The ledger is written before the file lands, so a ledger that cannot be
  written (the migration was not run again) stops the taking instead of repeating it. A json file that is not valid json yet (still being uploaded)
  is left for the next run; names are matched exactly (`SrvOnClick_b2r_...`, `SrvOnClickFiles_...`, case included). When the FTP cannot be read the
  import still runs on what already waits in the bucket.
- **Files and images** (`importFile:*`): the booking json only names them, so `ImportBookingService` saves a file / image row under the stored name
  without a place for the bytes. `ImportFileService` lists `import-files/` once and, for every received file a row names, **copies it to `files/`,
  gives the row that key (with the extension) and moves the received file to `loaded-files/`**. The row's kind decides what it takes (an image takes
  jpg, jpeg, png, webp or gif, a file also pdf, docx, xlsx and pptx); a received file nobody takes stays in `import-files/` until its booking is
  saved, and a row whose bytes have not arrived keeps waiting - the command names both. Only the types this system serves with a plain name (letters,
  digits, `_`, `-`, `.`) and the `SrvOnClickFiles_` prefix are read; there is no TIFF conversion here. The bookings serve their attachments from
  `files/` (`ATTACHMENTS_FOLDER_NAME`); a row saved before that still reads its old key until the next import points it at `files/`.
- An unreachable FTP makes the command fail (exit code 1, logged), it never looks like an empty folder.
