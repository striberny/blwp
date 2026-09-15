<?php
/**
 * Health check for the fetch pipeline.
 *
 * Exits 0 when healthy, 1 when something needs attention, and prints why. Run it from a
 * deploy script, from cron, or wrap it with an external uptime monitor — the exit code is
 * the machine interface.
 *
 * Usage: php backend/cron/check_health.php
 *
 * What this catches that the log cannot:
 *   - the scheduler stopped running      → last_run goes stale
 *   - a site is failing                  → last_run_ok false / per-site error
 *   - the data file was never written    → file missing or stale
 *
 * The last one matters because fetch_site_data() does not inspect the return value of
 * file_put_contents(). A permissions or disk problem would otherwise look like a success.
 */

date_default_timezone_set('Europe/Berlin');

if (file_exists('/home/deploy/blwp/bootstrap.php')) {
    require_once '/home/deploy/blwp/bootstrap.php';
} else {
    require_once __DIR__ . '/../bootstrap.php';
}

// The cron ticks every 3 minutes, so this tolerates five consecutive missed ticks before
// calling the pipeline stalled.
define('BLWP_STALE_AFTER_MINUTES', 15);

$state_file = BLWP_PRIVATE_PATH . '/config/cron-state.json';
$problems   = [];

if (!file_exists($state_file)) {
    // Without this file there is nothing to judge, but that is itself a red flag.
    echo "UNHEALTHY\n";
    echo "  - cron-state.json not found at {$state_file}\n";
    exit(1);
}

$state  = json_decode(file_get_contents($state_file), true) ?: [];
$global = $state['global'] ?? [];

// --- 1. Did a run happen recently? -----------------------------------------
$last_run = $global['last_run'] ?? null;

if (empty($last_run)) {
    $problems[] = 'No run recorded yet (last_run missing) — expected right after a deploy,'
        . ' before the first tick';
} else {
    $age_minutes = (time() - strtotime($last_run)) / 60;
    if ($age_minutes > BLWP_STALE_AFTER_MINUTES) {
        $problems[] = sprintf(
            'Last run was %.0f minutes ago (%s); expected within %d minutes'
            . ' — is the scheduler still enabled?',
            $age_minutes,
            $last_run,
            BLWP_STALE_AFTER_MINUTES
        );
    }
}

// --- 2. Did it succeed? ----------------------------------------------------
if (array_key_exists('last_run_ok', $global) && $global['last_run_ok'] === false) {
    $problems[] = 'The last run reported one or more failures';
}

// --- 3. Per-site results ---------------------------------------------------
$summary = $global['last_run_summary'] ?? [];

foreach ($summary as $domain => $entry) {
    if (empty($entry['ok'])) {
        $problems[] = sprintf('%s: %s', $domain, $entry['error'] ?? 'fetch failed');
        continue;
    }

    // A successful fetch proves nothing if the write failed silently, so check the
    // published file itself.
    $file = rtrim(BLWP_DATA_PATH, '/\\') . '/' . $domain . '.json';

    if (!file_exists($file)) {
        $problems[] = "{$domain}: data file missing ({$file})";
        continue;
    }

    $file_age = (time() - filemtime($file)) / 60;
    if ($file_age > BLWP_STALE_AFTER_MINUTES) {
        $problems[] = sprintf('%s: data file is %.0f minutes old', $domain, $file_age);
    }
}

// --- Report ----------------------------------------------------------------
if ($problems) {
    echo "UNHEALTHY\n";
    foreach ($problems as $problem) {
        echo "  - {$problem}\n";
    }
    exit(1);
}

printf("OK - last run %s, %d site(s) fresh\n", $last_run, count($summary));
exit(0);
