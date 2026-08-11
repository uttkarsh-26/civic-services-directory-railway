<?php

/**
 * @file
 * First-boot content bootstrap runner (guard + dispatcher).
 *
 * This is how the Civic Services Directory product ships on first boot:
 * content type, taxonomy, demo services, front-page view and disclaimer
 * block. The actual Drupal work happens in bootstrap-content.php, which is
 * executed under `drush php:script` so the full Drupal bootstrap (all
 * modules, config, entity APIs) is available.
 *
 * Design:
 *   - Fail closed: when the database is unconfigured, unreachable, or Drupal
 *     is not installed yet, it logs one line and exits 0. The installer runs
 *     before this script and this script retries on the next boot, so Apache
 *     always starts and the health check can report status.
 *   - Idempotent: bootstrap-content.php creates every object only when it is
 *     missing; re-runs are no-ops that exit 0.
 *   - Real failures exit 1 with a clear message; the entrypoint logs them and
 *     continues booting (see docker/entrypoint.sh).
 *
 * Never prints credentials.
 */

require __DIR__ . '/env.inc.php';

function civic_bootstrap_log(string $message): void {
  echo '[civic-services-directory] ' . $message . "\n";
}

$webroot = getenv('DRUPAL_WEBROOT') ?: '/opt/drupal/web';

$config = drupal_railway_db_config();
if ($config === NULL) {
  civic_bootstrap_log('content bootstrap: no database configuration; skipping (installer handles first boot)');
  exit(0);
}
if (!extension_loaded('pdo_pgsql')) {
  civic_bootstrap_log('content bootstrap: pdo_pgsql extension missing; skipping');
  exit(0);
}

try {
  $pdo = new PDO(drupal_railway_pdo_dsn($config), $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 10,
  ]);
  $pdo->query('SELECT 1');
}
catch (Throwable $e) {
  civic_bootstrap_log('content bootstrap: database not reachable; skipping (will retry on next boot)');
  exit(0);
}

if (!drupal_railway_is_installed($pdo)) {
  civic_bootstrap_log('content bootstrap: Drupal is not installed yet; skipping (installer runs first)');
  exit(0);
}
$pdo = NULL;

// Prefer the image's drush binary; fall back to PATH for local overrides.
$drush = $webroot . '/../vendor/bin/drush';
if (!is_file($drush)) {
  $drush = 'drush';
}
$script = __DIR__ . '/bootstrap-content.php';
$command = sprintf(
  'cd %s && %s php:script %s 2>&1',
  escapeshellarg($webroot),
  escapeshellarg($drush),
  escapeshellarg($script)
);

$output = [];
$status = 0;
exec($command, $output, $status);
foreach ($output as $line) {
  echo $line . "\n";
}

if ($status !== 0) {
  fwrite(
    STDERR,
    "[civic-services-directory] content bootstrap failed (exit $status). " .
    "The container will continue booting so the health check can still report " .
    "status; inspect the output above.\n"
  );
  exit(1);
}

civic_bootstrap_log('content bootstrap complete (idempotent; nothing duplicated)');
exit(0);
