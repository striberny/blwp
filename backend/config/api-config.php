<?php
// config/api-config.php

include_once __DIR__ . '/../lib/utils.php';
require_once __DIR__ . '/../Exceptions/MissingApiKey.php';

// --- Secrets (never committed) ---------------------------------------------
// The api-sports.io key and the registration shared secret live in secrets.php,
// which is gitignored. Copy secrets.example.php -> secrets.php and fill it in on
// every machine and on the production server. See docs/operations.md#security
$secrets_file = __DIR__ . '/secrets.php';

if (!file_exists($secrets_file)) {
  throw new MissingApiKey(
    "Missing {$secrets_file}. Copy secrets.example.php to secrets.php and fill in your credentials."
  );
}

$secrets = require $secrets_file;

// Fail loudly here rather than later with a confusing upstream 401/403.
foreach (['api_key', 'shared_secret'] as $required_key) {
  if (empty($secrets[$required_key])) {
    throw new MissingApiKey("config/secrets.php is missing a value for '{$required_key}'.");
  }
}

// Determine data path based on environment
if (file_exists('/var/www/api.fcbinside.de/htdocs/data')) {
  $data_path = '/var/www/api.fcbinside.de/htdocs/data/';
} else {
  // Dev: blwp-api is sibling to blwp
  $data_path = realpath(__DIR__ . '/../../api/data') . '/';
}

$config = [
  'api_key' => $secrets['api_key'],
  'league_ids' => [
    'bundesliga' => 78,
    // weitere Ligen können später hier ergänzt werden
  ],
  'default_season' => getCurrentSeason(), // fallback falls nicht gesetzt
  'limit_last' => 15,
  'limit_next' => 15,
  'default_widgets' => ['matches', 'standings', 'results'],
  'shared_secret' => $secrets['shared_secret'],
  'data_path' => $data_path,

  // Alerting — both Telegram values are needed for blwp_notify() to do anything. Empty means
  // every call becomes a silent no-op, which is the normal state in development.
  // See lib/notify.php and docs/operations.md.
  'telegram_bot_token' => $secrets['telegram_bot_token'] ?? '',
  'telegram_chat_id' => $secrets['telegram_chat_id'] ?? '',

  // Dead-man's switch from healthchecks.io (https://hc-ping.com/<uuid>). Pinged once at the
  // end of every tick; the monitor alerts when the ping stops arriving.
  'healthcheck_ping_url' => $secrets['healthcheck_ping_url'] ?? '',

  // Update intervals (in minutes)
  //
  // NOTE: the cron was deliberately simplified to "update every enabled site on
  // every tick". The per-site scheduling intervals (live_game, no_live_game,
  // standings_live, standings_no_live) were removed together with that logic —
  // they no longer had any effect. Only the two intervals below are live.
  'intervals' => [
    'standings_validation' => 60, // Minutes between cross-checks of the local table vs the API
    'http_rate_limit' => 5,       // Min. minutes between manual /v1/fetch.php calls per domain
    'alert_throttle' => 240,      // Minutes before the same alert key may notify again
  ],

  // Per-tick "everything is normal" logging. Off means a healthy run writes nothing at all
  // to logs/api.log. Turn it on while diagnosing something. See lib/logging.php.
  'log_verbose' => false,
];

// Resolved once here so lib/logging.php can answer "is verbose on?" without re-reading the
// config for every line it writes.
if (!defined('BLWP_LOG_VERBOSE')) {
  define('BLWP_LOG_VERBOSE', !empty($config['log_verbose']));
}

return $config;
