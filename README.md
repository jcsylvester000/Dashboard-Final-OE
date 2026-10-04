# OverEasy Dashboard

Internal marketing and task-tracking platform for the agency. Team members only:
there is no public sign-up and the app never sends email.

**Stack:** PHP 8.3+ (8.5 recommended, matches Forge) · Laravel 13 · Inertia 3 · Vue 3.5 (TypeScript, Composition API) ·
Tailwind CSS 4 + shadcn-vue · PostgreSQL 18 · Valkey (Redis-compatible) · Fortify (login, 2FA, passkeys) ·
spatie/laravel-permission 8. Deploys to Laravel Forge.

Build status: **P0 Foundation**, **P1 Auth, Admin & Access Control** (verified) and **P2 Workspaces, Projects & Tagging** (verified) **P3a Unified workflow** (verified) and **P3b Time tracking** are in place.
The phase checklist lives in `6 - Final Documentation/OverEasy Dashboard Build Game Plan - 2026-10-04/`.

---

## 1. One-time setup on Windows

Install these once:

| Tool | Version | Notes |
|---|---|---|
| [Laravel Herd for Windows](https://herd.laravel.com/windows) | latest | PHP 8.5 + Composer. PHP 8.3 also works (minimum for Laravel 13). |
| [Node.js](https://nodejs.org) | 24 LTS | Switch to 26 LTS after 28 Oct 2026. |
| [Docker Desktop](https://www.docker.com/products/docker-desktop/) | latest | Runs PostgreSQL 18 and Valkey. |
| [Git](https://git-scm.com/download/win) | latest | |

Check from PowerShell:

```powershell
php -v        # 8.3 or newer (8.5 recommended)
composer -V
node -v       # v24.x
docker -v
```

## 2. First run

Open PowerShell in this folder (`5 - Final Application\OverEasy Dashboard`):

```powershell
# 1. Start the database and cache
docker compose up -d

# 2. Environment file (edit DB_PASSWORD and SEED_ADMIN_EMAIL if you like)
copy .env.example .env

# 3. PHP + JS dependencies, app key, tables, seed data, frontend build
composer setup
```

`composer setup` runs `migrate --seed`. The seeder creates the five departments,
the roles, and the first **Super Admin**, then prints a one-time setup link:

```
Super Admin created: admin@overeasy.local
Open this one-time link to set the password (valid 72 hours):
http://localhost:8000/access/xxxxxxxx...
```

Start the app:

```powershell
composer dev
```

Open the printed link, set your password, then log in at http://localhost:8000.
If you lose the link before using it, generate a fresh one:

```powershell
php artisan tinker --execute="echo app(App\Domain\Identity\AccessLinkService::class)->issue(App\Models\User::role('super-admin')->first(), 'setup')['url'];"
```

> Using Herd's `.test` domain instead of `composer dev`? Set `APP_URL=http://overeasy-dashboard.test`
> in `.env` **before** seeding, so links point to the right host.

### Troubleshooting first run

| Symptom | Fix |
|---|---|
| `ports are not available ... 5432` | Postgres is published on **54320** to avoid this. Make sure `.env` has `DB_PORT=54320`, then `docker compose up -d`. |
| `requires php ^8.x but your php version ...` | Run `where.exe php` to see which PHP is first on PATH. Herd's PHP must come before `C:\php`, or update `C:\php`. |
| `could not find driver` / missing extension | In your `php.ini` enable: `pdo_pgsql`, `pgsql`, `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `intl`. Check with `php -m`. (Herd enables these already.) |
| `pint is not recognized` / `vendor/autoload.php` missing | `composer install` has not finished yet; fix the error above it first. |

## 2b. Run it locally (every day)

```powershell
cd "C:\Users\jcsyl\Desktop\Website Enhancement Agency\5 - Final Application\OverEasy Dashboard"
.\start-local.ps1
```

The script starts Docker (Postgres + Valkey), installs anything new, runs migrations, then
`composer dev`. Open http://localhost:8000. Stop with `Ctrl+C`, then `docker compose down` if you want.
If Windows blocks the script: `powershell -ExecutionPolicy Bypass -File .\start-local.ps1`.

## 3. Everyday commands

| Task | Command |
|---|---|
| Start DB + cache | `docker compose up -d` |
| Run the app (server, queue, Vite) | `composer dev` |
| Format PHP (Pint) | `composer lint` |
| Full check: style, static analysis, tests | `composer test` |
| Frontend lint + type check | `npm run check` and `npm run types:check` |
| Reset the database | `php artisan migrate:fresh --seed` (local only) |
| Stop services | `docker compose down` (data is kept in Docker volumes) |

Tests use an in-memory SQLite database, so they do not touch your local Postgres.
CI (GitHub Actions) runs the same tests against PostgreSQL 18.

## 4. How access works (P1)

- **No email, ever.** Admins create members in **Admin › Team members**. The app
  shows a one-time **setup link** (72 h) to copy and share privately.
- **Forgot password?** An admin opens the member and clicks **Issue reset link** (24 h, single use)
  or **Temporary password** (member must change it at next login).
- Admins can also **Reset 2FA**, **Sign out everywhere**, and **Deactivate** (immediate sign-out; no deletes).
- **Roles** (global): Super Admin, Admin, Finance, Department Lead, Member, Viewer.
  Edit the permission matrix in **Admin › Roles & access** (Super Admin only by default).
- **Departments:** Development, Research, Product, Marketing, SEO (editable).
- Every sign-in, failed sign-in and admin action is written to the append-only **Activity log**.

## 4b. Workspaces, projects & tagging (P2)

- **Workspaces** = one per client (`/workspaces`, `/w/{slug}`). Admins (`workspaces.manage`) create them and become **Owner**.
- **Workspace roles:** Owner (settings, members) · Lead (projects + labels, delete projects) · Member (create/edit projects) · Guest (read-only).
  People with `workspaces.view-all` (e.g. Finance) can read every workspace as Guest.
- **Switcher** in the sidebar remembers your last workspace.
- **Projects** live inside a workspace with a type: Project, Campaign, SEO engagement, Research study, Product release.
- **@mentions:** type `@` in a project brief to tag a workspace member. Tagged people see it on their dashboard ("Tagged in").
- **Labels:** colour tags per workspace (Settings tab).
- **References:** link any project/workspace to another (even across clients). The other side shows a backlink.
  You only ever see links to records you are allowed to open.

## 4c. Unified workflow (P3a)

- **Tasks** live in a client workspace and belong to a department (Development, Research, Product, Marketing, SEO).
  Same statuses for everyone: Backlog, To Do, In Progress, In Review, Blocked, Done.
- **Views:** Tasks (list + filters), **Board** (drag between columns), **My tasks** (all clients, by due date),
  **Department queue** (one department's work across all clients you can see).
- **Send to department (handoff):** creates the next department's task linked to this one. It waits in Backlog
  and moves to To Do automatically when this task is Done.
- **Waiting on:** a task cannot be moved to Done while a task it waits on is still open.
- **Workflow templates** (Admin › Workflow templates): Client Onboarding, Website Build, SEO Audit > Fix,
  Campaign Launch, Monthly Client Report. "Start a workflow" on the Tasks page creates the whole chain.
- **Discussion:** comments with @mentions (edit for 15 minutes), watchers, and a timeline of every change.

## 4d. Time tracking & calendar (P3b)

- **Timer:** "Start timer" on any task; the running timer shows in the top bar on every page with a Stop button.
  Starting another timer stops the first. Minutes round up; a forgotten timer is capped at 12 hours.
- **Log time:** hours + minutes, date, note, billable flag, from the task page.
- **My timesheet:** your week by task and day, with billable and approved totals.
- **Time approvals:** workspace owners/leads approve their team's time (not their own). Approved time is locked
  and is what invoices (P6) will bill.
- **Marketing / SEO details** on tasks: channel, campaign, deliverable; target URL, keyword, work type.
- **Calendar** tab per workspace: tasks by due date, month view.

## 4e. Notifications & alerts (P4) - in-app only, never email

- **Alerts for:** assigned to you, @mentions, handoffs (and "ready to start" when the task you wait on is done),
  comments and status changes on tasks you follow, due within 24h, overdue, and overdue > 2 days escalated to
  the department lead (or the workspace owners/leads). Never sent to the person who acted, and only to people
  who can open that workspace. Leaving a workspace hides its old alerts.
- **Bell** in the top bar (unread count) and **Inbox** in the sidebar: "Needs my attention" keeps assignments,
  mentions, handoffs and overdue items until you mark them Done or snooze them (1 hour / tomorrow / Monday).
- **Daily digest** card on the dashboard: unread, due today, overdue, last 24 hours of alerts.
- **Settings > Notifications:** per alert type choose Real time (inbox + bell + pop-up), Digest (inbox and
  dashboard only) or Off.
- **Hourly checks** run from the scheduler (`php artisan work:alerts`). `composer dev` now also runs the scheduler.
  On Forge: enable the scheduler and a queue worker for the site.
- **Admin > Failed jobs** (permission `system.manage`): see, retry or discard failed background jobs.
  Existing installs: run `php artisan db:seed --class=RolesAndPermissionsSeeder`, then tick the permission for
  Admin in Roles & access (Super Admin always has it).
- **Live updates (optional, Reverb):** without it the bell refreshes every 60 seconds. To turn it on:

```powershell
composer require laravel/reverb:^1.12
# then in .env (no need to run reverb:install - the app is already wired):
#   BROADCAST_CONNECTION=reverb
#   REVERB_APP_ID=overeasy
#   REVERB_APP_KEY=<any long random string>
#   REVERB_APP_SECRET=<another long random string>
#   plus the REVERB_HOST/PORT/SCHEME and VITE_REVERB_* lines from .env.example
php artisan config:clear
npm run build                   # VITE_REVERB_* are baked into the frontend
composer dev                    # now also starts reverb:start
```

## 4f. Dashboards & reports (P5)

- **My dashboard:** open / overdue / due-this-week counts, "Up next" (my tasks by due date), my workspaces with my
  task counts, recent @mentions (tasks, comments, projects), daily digest.
- **Workspace Overview:** work by department and status, blocked items, due in the next 2 weeks, recent activity.
- **Reports** (sidebar; workspace owners/leads see their own clients, admins see all): throughput per week, cycle
  time, overdue by department, workload by person, handoff wait time, time logged (by client / project / task /
  person / department, billable vs non-billable). Filter by client, department and dates; **Export CSV** opens in Excel.
- **Agency overview** (admins, `reports.view-all`): every active client's health and team workload, cached 5 minutes
  (Refresh button). Health: overdue = 5+ overdue tasks or 25%+ of open work overdue; at risk = any overdue or blocked.
- **Weekly snapshots:** saved every Monday 06:00 for last week (`php artisan reports:snapshot`); leads see them under
  the workspace **Reports** tab and can save last week on demand.

## 4g. Billing (P6a) - Finance only, no email to clients

- **Who:** users with `billing.manage` (Finance role; Super Admin). Members never see rates or invoice amounts.
- **Setup per client** (Billing > Clients > client): currency (PHP/USD), monthly/quarterly cycle, retainer + included
  hours, what happens to hours over the retainer (bill or absorb), payment terms, VAT %, invoice series, bill-to
  details, payment instructions. Expenses and fixed-fee projects are added on the same page.
- **Rates** (Billing > Rates, plus per-client overrides): most specific wins - client+person, client+department,
  client default, person, department, agency default; the latest rate effective on the work date applies.
  Optional internal cost per hour feeds profitability reports (P6b).
- **Periods:** add the next period, **Lock** it (nobody can add, change or delete time dated inside it), then
  **Create invoice**. Only approved, billable time is invoiced. If time is approved after the draft is built,
  click **Recalculate** before finalizing.
- **Invoices:** every line opens to show the exact time entries / expenses / project it bills. **Finalize** assigns
  the next number (INV-0001...) and freezes the invoice; changes are made with a **credit note** (CN-0001...).
  Unpaid invoices can be **voided** (their time becomes billable again). Record payments (bank, GCash, Maya, card,
  check, cash); the invoice list shows receivables aging (current / 1-30 / 31-60 / 61-90 / 90+).
- **Alerts:** Finance gets an in-app alert the day after an invoice's due date (`php artisan billing:alerts`, daily 07:00).
- **Money** is stored as integer centavos/cents; tax as basis points (12% = 1200). No floats.
- **PDFs (P6b):** on each invoice, **Download PDF** (the copy saved at finalize - identical every time) and
  **Work report PDF** (tasks, people and hours behind the invoice, grouped by department; Marketing section with
  campaign/channel/deliverable and SEO section with page/keyword/work type appear only when there is such work).
  Agency details on the PDFs come from `BILLING_COMPANY_*` in `.env`. PDFs are stored privately in
  `storage/app/private/invoices` - back this folder up on Forge.
- **Billing > Reports:** revenue by client and month, retainer utilisation, unbilled approved time (valued at
  current rates), profitability by project (needs internal cost rates).
- Requires `composer require "barryvdh/laravel-dompdf:~3.1.2"` (pure PHP, no Chrome on the server). On Windows use `~`, not `^` (cmd strips `^`; 3.1.0 does not support Laravel 13).

## 4h. API v1 for the mobile app and integrations (P7a)

- **Tokens:** Settings > API tokens. Choose Read only or Read & write and an expiry (max 365 days). The token
  is shown once. Deactivating a member revokes their tokens. Requires `composer require "laravel/sanctum:~4.3.3"`.
- **Calls:** `Authorization: Bearer <token>` and `Accept: application/json` to `/api/v1/...`:
  `GET me`, `GET workspaces`, `GET workspaces/{id}`, `GET workspaces/{id}/members`, `GET workspaces/{id}/projects`,
  `GET tasks?workspace_id=&assignee=me&status_id=&due=overdue|week&include_done=1&updated_since=&per_page=`,
  `GET tasks/{id}`, `GET tasks/{id}/comments`, `GET notifications?unread=1`,
  `GET workspaces/{id}/work-summary?from=YYYY-MM-DD&to=YYYY-MM-DD&department=seo` (leads / Finance),
  write: `POST workspaces/{id}/tasks`, `PATCH workspaces/{id}/tasks/{task}`, `POST tasks/{id}/comments`,
  `POST notifications/{id}/read`.
- **Rules:** same permissions as the web app (other clients' data -> 403). Errors are JSON
  (`{"message": ..., "errors": {...}}`); 401 without a valid token; 429 when over `API_PER_MINUTE` (default 120/min
  per token). Send `Idempotency-Key: <unique>` on POSTs so retries never create duplicates.
- **Work Summary** (workspace tab, leads and Finance): who worked on what in any date range, by task and person,
  with Marketing / SEO details; filter by department; export CSV or PDF.

## 4i. Files (P8)

- **Files card** on every task and project: attach a file (progress shown), image thumbnails, open or download,
  delete (uploader or workspace lead).
- **Private:** files live in `storage/app/private/attachments` (back it up on Forge) and are only reachable through
  signed links that expire after 10 minutes (`FILES_LINK_MINUTES`); you must also be able to open the workspace.
- **Checked on the server:** real file type (from the contents) and size. Names are cleaned; the stored file gets a
  random name. **Admin > File settings** (`system.manage`) sets the size limit (default 25 MB) and allowed types
  (images, PDF, Office, text/CSV by default; ZIP and video optional).
- **Thumbnails** need PHP's GD extension (`extension=gd` in php.ini); without it files still upload, just no preview.
- **UploadThing** storage plugs in later behind the same `FileStore` interface (`FILES_DRIVER`), no other code changes.

## 5. Push to GitHub

Repository: `https://github.com/jcsylvester000/Dashboard-Final-OE.git`

First push (from this folder):

```powershell
git init
git add .
git commit -m "P0-P1: Laravel 13 foundation, auth, admin and access control"
git branch -M main
git remote add origin https://github.com/jcsylvester000/Dashboard-Final-OE.git
git push -u origin main
```

Before committing, confirm secrets are not staged: `git status` must **not** list `.env`
(it is in `.gitignore`). Only `.env.example` belongs in the repo.

Later pushes:

```powershell
git add .
git commit -m "Describe the change"
git push
```

Recommended: create a `develop` branch for work in progress and merge to `main` through pull requests
so GitHub Actions runs the tests first:

```powershell
git checkout -b develop
git push -u origin develop
```

## 6. Project layout

```
app/Domain/          business logic by module (see app/Domain/README.md)
app/Http/Controllers Admin/, Auth/, Settings/ - thin controllers
app/Models/          Eloquent models
app/Policies/        record-level authorization
database/            migrations, seeders, factories
resources/js/pages/  Inertia pages (auth/, admin/, settings/, Dashboard.vue)
routes/web.php       app routes · routes/admin.php admin area · routes/settings.php
docker-compose.yml   PostgreSQL 18 + Valkey for local development
```
