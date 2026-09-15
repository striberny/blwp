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

return [
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

  // Update intervals (in minutes)
  //
  // NOTE: the cron was deliberately simplified to "update every enabled site on
  // every tick". The per-site scheduling intervals (live_game, no_live_game,
  // standings_live, standings_no_live) were removed together with that logic —
  // they no longer had any effect. Only the two intervals below are live.
  'intervals' => [
    'api_cache_refresh' => 60,  // getStandings() cache lifetime — protects the API quota
    'http_rate_limit' => 5,     // Min. minutes between manual /v1/fetch.php calls per domain
  ],
];
