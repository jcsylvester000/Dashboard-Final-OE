# Domain modules (modular monolith)

Business logic lives here, grouped by module. Eloquent models stay in `app/Models`,
HTTP controllers in `app/Http/Controllers`, so Laravel conventions keep working.

| Module | Phase | Owns |
|---|---|---|
| Identity | P1 | users, departments, roles/permissions, access links, activity log |
| Workspaces | P2 | client workspaces, membership, projects, labels, mentions |
| Work | P3 | tasks, statuses, dependencies, handoffs, templates, time entries |
| Notify | P4 | events, in-app notifications, digest, alerts |
| Reports | P5 | dashboards, reports, exports |
| Billing | P6 | billing profiles, rate cards, periods, invoices, PDFs, payments |
| Integrations | P7 | API tokens, AI providers (GetCody, VectorShift), webhooks |
| Files | P8 | attachments, FileStore drivers (local, UploadThing) |
| Geo | P9 | U.S. map locations and layers |

Rule: controllers stay thin and call a module service/action; modules talk to
each other through services or events, never through another module's tables.
