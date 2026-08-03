<?php

namespace Deployer;

require 'recipe/common.php';

/**
 * Recipe for Roots Bedrock projects on shared hosting with SSH access
 * (e.g. Hostido), where the webroot is fixed by the host (public_html)
 * and building (composer/npm) happens in CI, not on the server.
 *
 * Expects each release to be a pre-built artifact provided by CI:
 *   web/            - built Bedrock webroot (Sage dist, plugin build)
 *   vendor/          - composer install --no-dev from CI (Bedrock root)
 *   config/
 *   composer.json / composer.lock
 *
 * public_html on the server must be a SYMLINK to {{deploy_path}}/current/web
 * (set once, manually, outside this recipe).
 */

// --- Defaults, overridden per-project in deploy.php ---

set('shared_files', ['.env']);
set('shared_dirs', ['web/app/uploads']);
set('writable_dirs', ['web/app/uploads', 'web/app/cache']);
set('keep_releases', 3);

// Path to the artifact built locally by CI before `dep deploy` runs.
set('local_release_path', '.');

// Whether to back up web/app/uploads before each deploy (see recipe/backup.php).
// Off by default - only meaningful for projects with third-party content
// (forms, e-commerce), not e.g. a portfolio site.
set('backup_uploads', false);

// --- Ship the artifact (overrides deploy:update_code from common.php) ---
//
// common.php's default clones/fetches code from git ON THE SERVER. Here
// the server has no (and shouldn't need) composer/npm, so instead we
// rsync the artifact that CI already built.

desc('Upload the built artifact to the server');
task('deploy:update_code', function () {
    upload(get('local_release_path') . '/', '{{release_path}}/', [
        'options' => [
            '--exclude=.env',
            '--exclude=.env.*',
            '--exclude=node_modules',
            '--exclude=web/app/uploads',
        ],
    ]);
});

// --- Database snapshot right before the symlink swap ---
//
// An extra safety net specific to this deploy, independent of the
// recurring backup cron on the server.

desc('Snapshot the database before publishing the release');
task('deploy:backup_db', function () {
    $backupDir = '{{deploy_path}}/backups/db';
    $ts = date('Y-m-d-Hi');

    run("mkdir -p $backupDir");
    run("cd {{release_path}} && wp db export - --path=web/wp | gzip > $backupDir/pre-deploy-$ts.sql.gz");
});

before('deploy:symlink', 'deploy:backup_db');

// --- Main deploy task (same shape as common.php's, minus the remote
// build step - vendor/ is already part of the artifact built in CI) ---

desc('Deploy a Bedrock project');
task('deploy', [
    'deploy:prepare',
    'deploy:publish',
]);

after('deploy:failed', 'deploy:unlock');
