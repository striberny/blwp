<?php
/**
 * CLI Cron Script - Update all sites on every run
 *
 * Usage: php fetch_all.php
 *
 * Runs every 3 minutes via Task Scheduler. Updates every enabled site
 * unconditionally on every tick — no scheduling logic, no interval checks.
 * Reliability trumps API call savings (actual usage: ~3,400/day of 75,000).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Europe/Berlin');

if (file_exists('/home/deploy/blwp/bootstrap.php')) {
    require_once '/home/deploy/blwp/bootstrap.php';
} else {
    require_once __DIR__ . '/../bootstrap.php';
}

require_once BLWP_INC_DIR . '/APIFootball.php';
require_once BLWP_LIB_DIR . '/utils.php';
require_once BLWP_LIB_DIR . '/fetch-functions.php';

$config = require BLWP_CONFIG_DIR . '/api-config.php';

function blwp_log($message)
{
  $log_file = BLWP_PRIVATE_PATH . '/logs/api.log';
  $timestamp = date('Y-m-d H:i:s');
  file_put_contents($log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

function load_cron_state($config_dir)
{
  $state_file = $config_dir . '/cron-state.json';
  if (file_exists($state_file)) {
    return json_decode(file_get_contents($state_file), true);
  }
  return ['global' => []];
}

function save_cron_state($config_dir, $state)
{
  $state_file = $config_dir . '/cron-state.json';
  file_put_contents($state_file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$mapping_file = BLWP_PRIVATE_PATH . '/config/site-mapping.json';
$config_dir   = BLWP_PRIVATE_PATH . '/config';

// The registry is gitignored runtime data, so it may be absent on a fresh clone.
// register.php creates it when the first site registers itself.
if (!file_exists($mapping_file)) {
  blwp_log('site-mapping.json not found — no sites registered yet, updating standings only.');
}

$mapping    = file_exists($mapping_file)
  ? (json_decode(file_get_contents($mapping_file), true) ?: [])
  : [];
$cron_state = load_cron_state($config_dir);

// === GLOBAL STANDINGS — recalculated every run ===
update_global_standings($config, $cron_state, true);

// === PER-SITE — always fetch every enabled site ===
$run_summary = [];
$run_ok = true;

foreach ($mapping as $entry) {
  if (empty($entry['enabled']) || !$entry['enabled']) continue;

  $domain = $entry['domain'];
  $result = fetch_site_data($domain, $config);

  if ($result['success']) {
    // Deliberately not logged: a healthy tick is the normal case. The counts go into
    // cron-state.json instead, so success stays machine-readable and the log stays empty
    // until something is actually wrong.
    $run_summary[$domain] = [
      'ok' => true,
      'live' => $result['live_count'],
      'fixtures' => $result['fixtures_count'],
      'results' => $result['results_count'],
    ];
  } else {
    $run_ok = false;
    $run_summary[$domain] = ['ok' => false, 'error' => $result['error']];
    blwp_log("ERROR updating {$domain}: {$result['error']}");
  }
}

// Record the outcome so a stalled or failing cron is visible without reading the log.
// check_health.php reads exactly these keys.
$cron_state['global']['last_run'] = date('c');
$cron_state['global']['last_run_ok'] = $run_ok;
$cron_state['global']['last_run_summary'] = $run_summary;

save_cron_state($config_dir, $cron_state);
