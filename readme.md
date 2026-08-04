# bedrock-cicd

Reusable GitHub Actions workflow for deploying [Roots
Bedrock](https://roots.io/bedrock/) projects to shared hosting with SSH
access (e.g. Hostido), using a single live directory swapped atomically
via `mv` - no release-numbering, no extra tooling, just rsync + ssh.

## Server layout

```
deployments/
  app/               <- live directory; public_html is a symlink to app/web
  shared/
    .env
    uploads/          -> web/app/uploads
  backups/
    app_<timestamp>.tar.gz
```

Set up once, manually, outside this workflow:

```bash
ln -s deployments/app/web public_html
```

Each deploy builds a fresh release next to `app/`, links in `shared/`,
then does two atomic renames (old `app/` -> `backups/app_<ts>`, new
release -> `app/`). The old version is compressed and old backups
beyond `keep_backups` are pruned.

## Assumptions

- Building (`composer install`, `npm run build`) happens in CI, never
  on the server. The caller project's `Makefile` must expose a
  `cicd-build` target that produces a ready-to-ship artifact.
- PHP/Node versions are read from the caller repo's
  `.ddev/config.yaml` (`php_version`, `nodejs_version`) - not passed as
  workflow inputs, so CI can never drift from the local dev container.
- PHP-FPM on the server runs as the same user as SSH - no separate
  web-server-user permission handling is needed.

## Usage

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
      deploy_path: /home/user/domains/staging.example.com/deployments
    secrets:
      SSH_CONFIG: ${{ secrets.SSH_CONFIG }}
      SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
```

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
