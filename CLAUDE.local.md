# Backend is off-limits — ask first

**Hard rule, no exceptions:** Claude must not change anything in the backend projects.

Backend projects (read-only for Claude):
- `service-on-click-api` (the website's API)
- `service-on-click-mobile-api` (the mobile app's API)
- `service-on-click-mobile-app` (the mobile app)

"Change" means any edit, file creation or deletion, git operation (commit, checkout, cherry-pick, merge, reset, stash),
migration, seeder, `php artisan` command that writes data, or anything that writes to their databases or storage.

Allowed without asking: **reading** their code and **read-only** queries, to understand how the backend behaves.

If a fix seems to need a backend change, for any reason:
1. Stop. Do not make the change.
2. Explain to the user what would change, where, and why.
3. Make the change only after the user explicitly says yes, for that specific change.

The frontend (`service-on-click-app`) is where work happens; design every feature around what the API already offers.
