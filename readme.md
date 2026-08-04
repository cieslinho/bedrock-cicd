# bedrock-cicd

Reusable GitHub Actions workflow for deploying [Roots
Bedrock](https://roots.io/bedrock/) projects to shared hosting with SSH
access (e.g. Hostido). Mirrors the atomic-swap pattern used internally
at ParadiseMediaOrg (`job-deployment-wordpress.yml`), adapted for
Bedrock's `.env`-based config.

## Server layout

```
<deployment_dir_path parent>/
  app/               <- live release, swapped atomically via mv
  shared/
    .env
    uploads/
  backups/
    app/
      app_<timestamp>.tar.gz
    db/
      db_<timestamp>.sql.gz

<public_dir_path>/    <- e.g. public_html - a PERMANENT real directory,
                          never replaced. Refreshed after every deploy
                          with one symlink per top-level entry in
                          app/web/. Anything not part of the build
                          (e.g. a WordPress-generated .htaccess) is
                          left untouched instead of being wiped, unlike
                          a whole-directory symlink.
```

## Assumptions

- Building (`composer install`, `npm run build`) happens in CI, never
  on the server. The caller project's `Makefile` must expose a
  `cicd-build` target that produces a ready-to-ship artifact.
- PHP/Node versions are read from the caller repo's
  `.ddev/config.yaml` (`php_version`, `nodejs_version`).
- PHP-FPM on the server runs as the same user as SSH - no separate
  web-server-user permission handling is needed.

## Usage

Thin caller workflow in the consuming project:

```yaml
# .github/workflows/deploy-staging.yml
on:
  push:
    branches: [staging]

jobs:
  deploy:
    uses: cieslinho/bedrock-cicd/.github/workflows/deploy.yml@main
    with:
      stage: staging
      bedrock_root: wp
    secrets:
      SSH_CONFIG: ${{ secrets.SSH_CONFIG }}
      SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
```

## Required GitHub Environment `vars` (per environment, e.g. "staging")

| Variable | Example | Meaning |
|---|---|---|
| `BEDROCK_DEPLOYMENT_SERVER_DEPLOYMENT_DIR_PATH` | `/home/user/domains/x.com/deployments/app` | live release path |
| `BEDROCK_DEPLOYMENT_SERVER_PUBLIC_DIR_PATH` | `/home/user/domains/x.com/public_html` | permanent webroot |
| `BEDROCK_DEPLOYMENT_SERVER_UPLOADS_DIR_PATH` | `/home/user/domains/x.com/deployments/shared/uploads` | persistent uploads |
| `BEDROCK_DEPLOYMENT_SERVER_ENV_PATH` | `/home/user/domains/x.com/deployments/shared/.env` | persistent `.env` |
| `BEDROCK_DEPLOYMENT_LOCAL_PUBLIC_DIR_PATH` | `web` | webroot path inside the built artifact |
| `BEDROCK_DEPLOYMENT_LOCAL_UPLOADS_DIR_PATH` | `web/app/uploads` | uploads path inside the built artifact |
| `BEDROCK_DEPLOYMENT_SERVER_POST_DEPLOYMENT_COMMANDS` | `wp cache flush --path=web/wp` | optional, run on the server after deploy |

## Required secrets (per GitHub Environment)

- `SSH_PRIVATE_KEY` - private key authorized on the target host.
- `SSH_CONFIG` - a `Host` block, any alias name (the workflow reads it
  from the first `Host` line):
  ```
  Host whatever-you-like
    HostName host.example.com
    User deployuser
    Port 22
  ```

## One-time server setup

`SERVER_PUBLIC_DIR_PATH` must exist as a real directory before the
first deploy (not a symlink) - e.g. `mkdir -p public_html`. Everything
inside it is managed by the workflow from then on.

If you need `.htaccess` (or any other file WordPress or the server
writes directly, not produced by the build) to survive every deploy,
create it as a **real file directly in `SERVER_PUBLIC_DIR_PATH`**, not
as a symlink into `app/web/`. The refresh step only adds/replaces
symlinks for entries that exist in the new release - it never deletes
a pre-existing real file with no matching build entry, so a real file
here persists automatically. A symlink pointing into `app/web/` will
dangle the moment that release gets swapped away.
