# OverEasy Dashboard

Internal marketing and task-tracking platform for the agency. Team members only:
there is no public sign-up and the app never sends email.

**Stack:** PHP 8.3+ (8.5 recommended, matches Forge) · Laravel 13 · Inertia 3 · Vue 3.5 (TypeScript, Composition API) ·
Tailwind CSS 4 + shadcn-vue · PostgreSQL 18 · Valkey (Redis-compatible) · Fortify (login, 2FA, passkeys) ·
spatie/laravel-permission 8. Deploys to Laravel Forge.

Build status: **P0 Foundation** and **P1 Auth, Admin & Access Control** are in place.
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
