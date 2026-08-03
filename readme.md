# bedrock-cicd

Reusable Deployer recipe and GitHub Actions workflow for deploying
[Roots Bedrock](https://roots.io/bedrock/) projects to shared hosting
with SSH access (e.g. Hostido), using an atomic release/symlink layout.

## Assumptions

- The server's webroot (`public_html` or equivalent) is a symlink to
  `{{deploy_path}}/current/web`, set up once, manually, outside this
  recipe.
- Building (`composer install`, `npm run build`) happens in CI, never
  on the server. The caller project's `Makefile` must expose a
  `cicd-build` target that produces a ready-to-ship artifact (Bedrock
  `vendor/`, built theme/plugin assets).
- `deployer/deployer` and this recipe are **not** composer dependencies
  of the Bedrock project — they're CI tooling, fetched separately so
  `composer install --no-dev` in the build step can't strip them.

## Usage

In the consuming project, add a thin caller workflow:

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

And a `deploy.php` in `bedrock_root`:

```php
<?php

namespace Deployer;

require getenv('BEDROCK_CICD_RECIPE') ?: __DIR__ . '/../../bedrock-cicd/recipe/bedrock.php';

set('shared_dirs', ['web/app/uploads']);
set('shared_files', ['.env']);
set('local_release_path', getenv('CI_BUILD_PATH') ?: __DIR__);

host('staging')
    ->setHostname('your-ssh-config-alias')
    ->set('deploy_path', '/home/user/domains/staging.example.com/deployments');
```

## Required secrets (per GitHub Environment)

- `SSH_PRIVATE_KEY` — private key authorized on the target host.
- `SSH_CONFIG` — a `Host` block matching the alias used in `deploy.php`'s
  `setHostname()` (HostName, User, Port).
