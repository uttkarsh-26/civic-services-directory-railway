# Deploy and Host Civic Services Directory on Railway

Deploy a ready-to-publish, independent civic-services directory built on
Drupal 11 + PostgreSQL in one click. The site installs itself on first boot
**and ships fully populated**: a "Service" content type, a "Service
Categories" taxonomy, six clearly-labeled fictional demo services, a
category-grouped front page, and the independent-service disclaimer — no
manual steps. This template pins a known-good dependency graph (Drupal core,
PHP 8.5, Apache) and wires persistent storage and a readiness health check.

## About Hosting

Everything runs on Railway's managed platform:

- **Web service** — Apache + PHP 8.5 (Drupal 11.4.x), built from the public `uttkarsh-26/civic-services-directory-railway` repo. Apache adapts to Railway's dynamic `PORT`, and a `/health.php` endpoint drives the platform health check.
- **PostgreSQL 18** — managed database service with SSL, auto-provisioned. Drupal is wired to it through `${{Postgres.DATABASE_URL}}`.
- **Persistent volume** — a 500 MB volume mounts at `/data`: uploaded files, the hash salt, and configuration exports survive redeploys. No volume, no persistence — files reset on every deploy.
- **Automatic install** — on first boot the entrypoint runs `drush site:install` (idempotent, guarded by a PostgreSQL advisory lock), then the content bootstrap creates the directory product (content type, taxonomy, demo services, front-page view, disclaimer block). Both are idempotent — redeploys create nothing twice.

## What You Get

- **A working directory out of the box** — the homepage lists services grouped
  by category, every entry carries an "Official source URL" link to the real
  government portal, and a footer states the independence promise: *"Independent
  information service — not a government website. Always confirm on official
  government portals."*
- **A clear content model** — add services from the UI (Content → Add content →
  Service): category, agency, summary, full description, eligibility, required
  documents, official source URL, last-verified date.
- **Independent branding** — the site is an independent civic-tech directory:
  no government seals, no .gov styling, no domain mimicry. All demo content is
  fictional and labeled `[Demo entry — fictional, for template preview]`.

## Why Deploy

- **Reproducible** — the dependency graph is pinned and verified by CI on every commit; Composer issues fail the build instead of shipping.
- **Battle-tested on Railway** — this template ships with fixes for the two platform-specific pitfalls that break naive Drupal containers: Railway's builder rejects Dockerfile `VOLUME` instructions (volumes are attached natively instead), and the Drupal base image's multi-MPM state is resolved at boot so Apache always starts clean.
- **Secret-safe** — the admin password is a required deploy-time variable (never baked into the image); private state (hash salt, config exports) lives under `/data`, outside the web root.
- **Zero local setup** — no Drush, no Composer, no PHP install on your machine. Point, click, deploy.

## Common Use Cases

- Launch an independent civic services directory for a city, district, or community — pre-populated and ready to edit.
- Publish a government-services guide that links out to official portals without imitating them.
- Use as a starting point for custom Drupal work — `composer require` your modules in CI, keep the same storage and health-check guarantees.

## Dependencies for

- Drupal core 11.4.x (latest patch, updated weekly by Dependabot)
- PostgreSQL 18 (Railway managed service)
- PHP 8.5 + Apache on Debian Bookworm (official Drupal image)
- Composer-managed contrib dependencies, pinned and audited

### Deployment Dependencies

- `DATABASE_URL` — provided automatically via `${{Postgres.DATABASE_URL}}`
- `DRUPAL_ACCOUNT_PASS` — required; choose a strong admin password at deploy time
- `DRUPAL_ACCOUNT_NAME` (default `admin`) and `DRUPAL_ACCOUNT_MAIL` — the initial admin account
- `DRUPAL_SITE_NAME` — site title used by the installer
- `/data` volume on the web service for persistent files and private state
