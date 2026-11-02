# Deploying REMEDI to Railway

Railway rather than Vercel, and MySQL rather than Supabase — the reasons are at
the bottom, under "Why not Vercel + Supabase". The short version is that this
app is a long-running PHP server with a Python subprocess attached, and its
queries are written in MySQL.

Everything below assumes the repository is on GitHub and you are deploying the
default branch.

---

## 1. The app service

1. [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub repo**
   → pick the REMEDI repository.
2. Railway finds `Dockerfile` and `railway.json` and uses them. No build
   configuration to set — do **not** let it pick a Nixpacks PHP preset instead.

The first build takes 6–10 minutes: it compiles the PHP extensions and installs
pandas, statsmodels and numpy into the forecasting virtualenv. Later builds
reuse those layers unless `composer.lock` or `requirements.txt` changes.

## 2. The database

**New** → **Database** → **Add MySQL**. In the same project, so the two share a
private network and the app can reach it without egress charges.

**Or PostgreSQL** (the app runs on either since 2026-11-02; `App\Support\Sql` holds
what differs): **Add PostgreSQL** instead and set `DB_CONNECTION=pgsql`. See "Moving
to PostgreSQL" at the end for an existing install.

Create the database with ICU sorting, or mixed-case names sort differently from MySQL:

```sql
CREATE DATABASE remedi TEMPLATE template0 ENCODING 'UTF8'
    LOCALE_PROVIDER icu ICU_LOCALE 'und' LOCALE 'C.UTF-8';
```

## 3. Variables

App service → **Variables** → **Raw editor**, and paste:

```
APP_NAME=REMEDI
APP_ENV=production
APP_DEBUG=false
# ^ enforced, not just documented -- docker/entrypoint.sh refuses to start
#   the container if APP_ENV=production and this is true, rather than
#   trusting it was set correctly here.
APP_KEY=
APP_URL=https://your-app.up.railway.app
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

CACHE_DRIVER=database
SESSION_DRIVER=database
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

Four of those need saying out loud:

- **`APP_KEY`** — generate locally with `php artisan key:generate --show` and
  paste the whole `base64:…` string. Without it every encrypted cookie fails and
  nobody can sign in.
- **`CACHE_DRIVER` / `SESSION_DRIVER`, not `CACHE_STORE`.** This is a Laravel
  12 app on a Laravel 10-style skeleton, and `config/cache.php` reads
  `env('CACHE_DRIVER')`. The Laravel 11+ name is ignored silently — you would
  get `file` on a disk that is wiped on every deploy.
- **`APP_TIMEZONE=Asia/Manila`** — `config/app.php` already defaults to it, but
  set it explicitly. Reports, the audit trail's date presets and
  `SalesHistory::reportableThrough()` all key off "today", and the container
  clock is UTC.
- **`${{MySQL.…}}`** are Railway's own references, resolved at deploy time.
  Leave them as references rather than pasting the values; the credentials
  rotate.

Set `APP_URL` properly once Railway has given you the domain
(**Settings → Networking → Generate Domain**).

## 4. Seed, once

Migrations run automatically on every deploy, from `docker/entrypoint.sh`.
**Seeding does not, and must not** — the seeders read a ~15 MB CSV and insert
around 114k `sales_history` rows, which would add minutes to every deploy and
collide on the second attempt.

Run it by hand, once, after the first successful deploy:

```bash
npm i -g @railway/cli
railway login
railway link
railway run --service <app-service-name> php artisan db:seed --force
```

That gives you the catalogue, the batches, the sales history, and two accounts:
`admin@remedi.com` / `password` and `staff@remedi.com` / `password`.
**Change both immediately** — they are in the repository.

## 5. The nightly forecast

`app/Console/Kernel.php` schedules `forecast:generate` at 02:00, but nothing
runs Laravel's scheduler in a container unless you give it something to run it.
On Railway that is a second service:

**New** → **Empty Service** → same repo → **Settings**:

- **Cron Schedule**: `0 18 * * *`
- **Custom Start Command**:
  `php artisan forecast:generate --source=mysql --python=python`

`18:00 UTC` is `02:00` in Manila. Railway's cron is UTC and does not follow
`APP_TIMEZONE`; the +8 offset is the whole reason that line is not `0 2 * * *`.

`--python=python` because the image puts the forecasting virtualenv on PATH as
`python`; the command's signature defaults to `python3`, which on Debian is the
system interpreter without pandas.

Copy the same `DB_*` variables onto this service. It does **not** need
`APP_KEY`, a domain, or a healthcheck.

The run takes ~3 minutes on the seeded catalogue and holds a database
connection throughout. The sales-units pipeline
(`sales-forecast:generate`) stays manual, as it is locally.

## 6. Check it

```bash
railway logs --service <app-service-name>
```

A healthy boot prints, in order: `==> REMEDI boot`, `==> database is up`, the
migration table, then `==> serving on :8080`. Then open the domain — you should
land on the login page, which is also what the healthcheck hits.

---

## Gotchas

| Symptom | Cause |
|---|---|
| 405 on every page, nobody can sign in | Something ran `route:cache`. It drops GET from `/`. The entrypoint deliberately runs only `config:cache` and `view:cache` — never `optimize`. |
| Everyone signed out after each deploy | `SESSION_DRIVER` is still `file`. The container filesystem is rebuilt every deploy. |
| Dashboard slow on every load, alerts stale | `CACHE_DRIVER` is `file`, or you set `CACHE_STORE` and this skeleton did not read it. |
| Reports error on `STRAIGHT_JOIN` or `DATE_FORMAT` | The database is PostgreSQL. It has to be MySQL. |
| Forecast cron exits immediately | `DB_*` not copied onto the cron service, or `--python=python` omitted. |
| Forecast cron runs but writes nothing | Not enough history — the cascade needs ≥3 months per product. Confirm the seed actually ran. |
| Dates and "today" one day out | `APP_TIMEZONE` unset; the container is UTC and Manila is +8. |
| Seeder "succeeds" but tables are empty | The CSVs did not make it into the image. They are excluded by neither `.gitignore` nor `.dockerignore` on purpose — the seeders skip silently when a file is missing. |

## Cost

The app service idles at roughly 512 MB. The forecast run peaks well above that
— statsmodels fitting several models per product — so give the **cron service**
at least 2 GB, or it is OOM-killed partway through and leaves the forecast
tables half-refreshed. Railway's $5 Hobby credit covers a demo comfortably;
sustained use of both services plus MySQL runs $10–20/month.

## Why not Vercel + Supabase

Worth writing down, since it was tried first:

- **Vercel is serverless.** Functions are capped in the tens of seconds and are
  rebuilt per request. `/dashboard` takes 5–12s warm and `forecast:generate`
  takes minutes; the second cannot run there at all.
- **There is no Python runtime beside the PHP one.** Both forecasting pipelines
  shell out through `Symfony\Process` to a script that imports pandas and
  statsmodels. One container carrying both runtimes is the requirement, which is
  what the `Dockerfile` builds.
- **Vercel's build step guessed Vite** and ran `vite build`. No view in this app
  references `@vite` — the Tailwind/PostCSS toolchain is inherited Breeze
  scaffolding and is inert. The build produced an asset nothing loads.
- **Supabase is PostgreSQL**, and the two reasons under §2 apply.

## Moving to Supabase (PostgreSQL)

Chosen 2026-11-02, for cost: Vercel + a Singapore Supabase project, Railway dropped once
that is proven. The steps under "Moving to PostgreSQL" below apply, with these changes:

- **Connect through the session pooler**: port 5432 on the `pooler.supabase.com` host, user
  `postgres.<project ref>`, database `postgres`, `DB_SSLMODE=require`. It takes IPv4 and
  supports prepared statements. Port 6543 (transaction mode) does not support them, and the
  app has prepared statements on, so do not use it without changing that.
- **Use the `postgres` database Supabase provides**; skip the `CREATE DATABASE` above.
- **Copy with `--without-archives`**: the free plan's quota is 500 MB and the archives stay in
  the MySQL backup.
- **Run `php artisan db:lock-public-tables` straight after the copy, and after any migration
  that adds a table.** Tables in `public` can be reachable through Supabase's Data API, and
  `users` holds password hashes.
- **`vercel.json` is pinned to `sin1`** on this branch, beside the database. It must merge
  together with the switch of Production's `DB_*`: Singapore against the European MySQL
  would be far slower than today.
- **Nothing runs the monthly forecast without Railway.** Vercel has no Python. Run both
  commands by hand on the 1st, from a machine with the Python packages, against Supabase.
- **A free project pauses after a week of low activity.** Open the site in the days before
  anything that depends on it.

## Moving to PostgreSQL

Rehearsed locally on 2026-11-02, not yet run against the live hosts. Both drivers are in
the image, so deploying this code changes nothing until `DB_CONNECTION` changes.

1. Take a verified backup of MySQL (see "Gotchas": check the dump's terminator).
2. Create the PostgreSQL database (the `CREATE DATABASE` above) and run
   `php artisan migrate --force` with `DB_CONNECTION=pgsql` and `DB_*` pointing at it.
3. Stop the till, so no sale lands in MySQL after the copy.
4. With `SOURCE_DB_HOST` / `PORT` / `DATABASE` / `USERNAME` / `PASSWORD` pointing at MySQL:

   ```bash
   php artisan db:copy-mysql-to-postgres
   ```

   ```bash
   php artisan db:copy-mysql-to-postgres --write
   ```

   The first is a dry run. MySQL is only read; the copy refuses a target already holding
   rows. `sessions` and the cache are not copied, so everyone signs in again.
5. Switch `DB_*` on **both** hosts. Vercel: confirm the PHP runtime has `pdo_pgsql` first.
   Keep Vercel's function region next to the new database.
6. Check the dashboard, a report, `/forecast`, a sale and a void. Leave MySQL untouched
   until that has run for a while: switching `DB_*` back is the way back, but any sale
   rung up on PostgreSQL in the meantime would not be in MySQL.
