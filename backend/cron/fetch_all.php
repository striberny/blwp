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

// === GLOBAL STANDINGS — force recalculation every run ===
// getStandings (API) is still rate-limited internally to once per 60 min.
update_global_standings($config, $cron_state, true);

// === PER-SITE — always fetch every enabled site ===
foreach ($mapping as $entry) {
  if (empty($entry['enabled']) || !$entry['enabled']) continue;

  $domain = $entry['domain'];
  $result = fetch_site_data($domain, $config, true);

  if ($result['success']) {
    blwp_log(sprintf(
      'Updated %s: live=%d, fixtures=%d, results=%d',
      $domain,
      $result['live_count'],
      $result['fixtures_count'],
      $result['results_count']
    ));
  } else {
    blwp_log("ERROR updating {$domain}: {$result['error']}");
  }
}

save_cron_state($config_dir, $cron_state);
