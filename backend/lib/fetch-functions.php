<?php

/**
 * Shared fetch functions for both CLI cron and HTTP endpoints
 */

require_once __DIR__ . '/../inc/APIFootball.php';
require_once __DIR__ . '/utils.php';

/**
 * Fetch and update data for a single site
 *
 * @param string $domain Site domain
 * @param array $config API configuration
 * @param bool $force_update Bypass schedule checks and force update
 * @return array Result with success status and details
 */
function fetch_site_data($domain, $config, $force_update = false)
{
  $api = new APIFootball($config['api_key']);
  $data_dir = $config['data_path'];
  $mapping_file = __DIR__ . '/../config/site-mapping.json';

  // Load site mapping (gitignored runtime data — may be absent on a fresh clone)
  $mapping = file_exists($mapping_file)
    ? (json_decode(file_get_contents($mapping_file), true) ?: [])
    : [];
  $site = null;

  foreach ($mapping as $entry) {
    if ($entry['domain'] === $domain && !empty($entry['enabled'])) {
      $site = $entry;
      break;
    }
  }

  if (!$site) {
    return [
      'success' => false,
      'error' => 'Site not found or disabled',
      'domain' => $domain
    ];
  }

  $team_id = $site['team_id'];
  $season = $site['season'] ?? $config['default_season'];
  $limit_last = $config['limit_last'];
  $limit_next = $config['limit_next'];

  try {
    // Fetch upcoming and finished games — check for API errors before proceeding
    $raw_fixtures = $api->getFixtures($team_id, $limit_next);
    if (isset($raw_fixtures['error'])) {
      blwp_log("API error in getFixtures({$team_id}): HTTP {$raw_fixtures['status']} — aborting to preserve stale data");
      return ['success' => false, 'error' => 'getFixtures API error: HTTP ' . $raw_fixtures['status'], 'domain' => $domain];
    }

    $raw_results = $api->getResults($team_id, $limit_last);
    if (isset($raw_results['error'])) {
      blwp_log("API error in getResults({$team_id}): HTTP {$raw_results['status']} — aborting to preserve stale data");
      return ['success' => false, 'error' => 'getResults API error: HTTP ' . $raw_results['status'], 'domain' => $domain];
    }

    $raw_today = $api->getFixturesByDate($team_id, date('Y-m-d'));
    if (isset($raw_today['error'])) {
      blwp_log("API error in getFixturesByDate({$team_id}): HTTP {$raw_today['status']} — aborting to preserve stale data");
      return ['success' => false, 'error' => 'getFixturesByDate API error: HTTP ' . $raw_today['status'], 'domain' => $domain];
    }

    $fixtures_raw = filterFixtures($raw_fixtures);
    $results_raw  = filterFixtures($raw_results);

    // Fetch all of today's games regardless of status.
    // This bridges the gap where in-progress games fall between 'next' (upcoming)
    // and 'last' (finished) and would otherwise go missing entirely.
    $today_raw = filterFixtures($raw_today);

    // Merge today's games first — deduplication in categorizeGames handles overlaps
    $fixtures_raw = array_merge($today_raw, $fixtures_raw);

    // Categorize games: live (today), fixtures (future), results (finished only)
    $categorized = categorizeGames($fixtures_raw, $results_raw);

    // Build clean public API data
    $data = [
      'meta' => [
        'domain' => $domain,
        'team_id' => $team_id,
        'generated_at' => date('c'),
        'season' => $season
      ],
      'live' => $categorized['live'],
      'fixtures' => $categorized['fixtures'],
      'results' => $categorized['results']
    ];

    // Save site data
    $data_file = $data_dir . '/' . $domain . '.json';
    file_put_contents($data_file, json_encode($data, JSON_UNESCAPED_SLASHES));

    // Derive has_game_today from all available data — do not rely solely on
    // $today_raw (getFixturesByDate) which can silently return empty responses.
    // A game counts as "today" if it is live, or is in fixtures with isToday=true.
    $has_game_today = !empty($today_raw)
      || !empty($categorized['live'])
      || !empty(array_filter($categorized['fixtures'], fn($g) => !empty($g['isToday'])));

    return [
      'success' => true,
      'domain' => $domain,
      'live_count' => count($categorized['live']),
      'fixtures_count' => count($categorized['fixtures']),
      'results_count' => count($categorized['results']),
      'has_game_today' => $has_game_today,
      'generated_at' => date('c')
    ];
  } catch (Exception $e) {
    return [
      'success' => false,
      'error' => $e->getMessage(),
      'domain' => $domain
    ];
  }
}

/**
 * Update global standings (shared between cron and HTTP)
 *
 * @param array $config API configuration
 * @param array $cron_state Current cron state (passed by reference, will be modified)
 * @param bool $force_update Force update regardless of schedule
 * @return array Updated cron state
 */
function update_global_standings($config, &$cron_state, $force_update = false)
{
  $api = new APIFootball($config['api_key']);
  $data_dir = $config['data_path'];
  $competition_id = $config['league_ids']['bundesliga'];
  $season = $config['default_season'];

  // Only the API standings cache interval still matters: it caps how often the
  // quota-consuming getStandings() call is made. The per-site scheduling
  // intervals were dropped with the cron simplification (see docs/architecture.md).
  $intervals = $config['intervals'] ?? [];
  $api_cache_interval = $intervals['api_cache_refresh'] ?? 60;

  $current_hour = (int) date('H');
  $current_minute = (int) date('i');

  // Check if update is needed
  $last_standings_update = $cron_state['global']['last_standings_update'] ?? null;
  $minutes_since_standings_update = $last_standings_update ? (time() - strtotime($last_standings_update)) / 60 : 9999;

  // Both callers (the CLI cron and /v1/fetch.php) always pass force_update = true,
  // so standings are recalculated on every tick. The fallback branch below is kept
  // for any future non-forced caller.
  if ($force_update) {
    $should_update = true;
  } else {
    // Match hours (13:00-23:00), or at least once a day
    $is_match_hours = $current_hour >= 13 && $current_hour <= 23;
    $should_update = $is_match_hours || $minutes_since_standings_update > 1440;
  }

  if (!$should_update) {
    // blwp_log("Skipping global standings update (last updated: {$last_standings_update})");
    return $cron_state;
  }

  // blwp_log("Updating global Bundesliga standings" . ($force_update ? " (forced)" : ""));

  try {
    // Calculate standings from all league fixtures (season snapshot)
    $all_league_fixtures = $api->getLeagueFixtures($competition_id, $season);
    $fixture_count = isset($all_league_fixtures['response']) ? count($all_league_fixtures['response']) : 0;
    // blwp_log("Calculating standings from {$fixture_count} league fixtures");

    // Detect live games by scanning today's fixtures for in-play statuses
    $live_statuses = ['1H', 'HT', '2H', 'ET', 'P', 'BT', 'LIVE', 'INT', 'SUSP'];
    $league_live_games = [];
    foreach ($all_league_fixtures['response'] ?? [] as $fixture) {
      $status = $fixture['fixture']['status']['short'] ?? '';
      if (in_array($status, $live_statuses)) {
        $league_live_games[] = [
          'fixture_id' => $fixture['fixture']['id'],
          'home_id' => $fixture['teams']['home']['id'],
          'away_id' => $fixture['teams']['away']['id'],
          'home_name' => $fixture['teams']['home']['name'],
          'away_name' => $fixture['teams']['away']['name'],
          'status' => $status
        ];
      }
    }

    if (!empty($league_live_games)) {
      blwp_log("Detected " . count($league_live_games) . " live Bundesliga game(s):");
      foreach ($league_live_games as $game) {
        blwp_log("  - {$game['home_name']} vs {$game['away_name']} ({$game['status']})");
      }
    }

    $calculated_standings = calculateStandings($all_league_fixtures, $competition_id);
    // blwp_log("Calculated standings with " . count($calculated_standings) . " teams");

    // Fetch API standings: at configured interval during live games, daily at 23:00, or if cache empty
    $minutes_since_api_cache = !empty($cron_state['global']['api_cache_timestamp'])
      ? (time() - strtotime($cron_state['global']['api_cache_timestamp'])) / 60
      : 9999;

    // Never let $force_update bypass the api_cache_interval — that would fire
    // getStandings every cron tick and exhaust the rate limit.
    $fetch_api_standings =
      ($minutes_since_api_cache >= $api_cache_interval) ||
      ($current_hour === 23 && $current_minute < 5) ||
      empty($cron_state['global']['api_standings_cache']);

    if ($fetch_api_standings) {
      // blwp_log("Fetching API standings for metadata merge and validation");
      $api_standings_raw = $api->getStandings($competition_id, $season);
      $api_standings = filterStandings($api_standings_raw, true);

      // Validate our calculations
      validateStandings($calculated_standings, $api_standings, 'global');

      // Cache API standings
      $cron_state['global']['api_standings_cache'] = $api_standings;
      $cron_state['global']['api_cache_timestamp'] = date('c');
    }

    // Merge calculated + API metadata
    $api_cache = $cron_state['global']['api_standings_cache'] ?? [];
    $global_standings = !empty($api_cache)
      ? mergeStandingsWithAPI($calculated_standings, $api_cache)
      : $calculated_standings;

    // Save global standings.json
    $standings_file = $data_dir . '/standings.json';
    $standings_data = [
      'meta' => [
        'generated_at' => date('c'),
        'season' => $season,
        'competition_id' => $competition_id,
        'competition_name' => 'Bundesliga'
      ],
      'standings' => $global_standings
    ];
    file_put_contents($standings_file, json_encode($standings_data, JSON_UNESCAPED_SLASHES));
    blwp_log("Saved global standings.json");

    // Update cron state
    $cron_state['global']['last_standings_update'] = date('c');
  } catch (Exception $e) {
    blwp_log("ERROR updating standings: " . $e->getMessage());
  }

  return $cron_state;
}

/**
 * Categorize games into live, fixtures, and results based on date and status
 *
 * @param array $fixtures_raw Raw fixtures from API
 * @param array $results_raw Raw results from API
 * @return array Categorized games ['live' => [], 'fixtures' => [], 'results' => []]
 */
function categorizeGames($fixtures_raw, $results_raw)
{
  $today = date('Y-m-d');
  $live = [];
  $fixtures = [];
  $results = [];

  // Track seen fixture IDs to prevent duplicates across all arrays
  $seen_ids = [];

  // Statuses considered "finished"
  $finished_statuses = ['FT', 'AET', 'PEN', 'AWD', 'WO'];

  // Statuses considered "actually live" (game in progress)
  $in_play_statuses = ['1H', 'HT', '2H', 'ET', 'P', 'BT', 'LIVE', 'INT', 'SUSP'];

  // Statuses for not yet started games
  $not_started_statuses = ['TBD', 'NS'];

  // blwp_log("categorizeGames - Today: {$today}");

  // Process fixtures (upcoming games) first - they take priority
  foreach ($fixtures_raw as $game) {
    $fixture_id = $game['id'] ?? null;

    // Skip if we've already seen this fixture
    if ($fixture_id && isset($seen_ids[$fixture_id])) {
      // blwp_log("Fixture {$fixture_id} - SKIPPED (duplicate)");
      continue;
    }

    $gameDateTime = new DateTime($game['date']);
    $gameDateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
    $gameDate = $gameDateTime->format('Y-m-d');
    $status = $game['status']['short'] ?? 'NS';
    $isToday = ($gameDate === $today);

    // blwp_log("Fixture {$fixture_id} - Date: {$game['date']}, Parsed: {$gameDate}, Status: {$status}");

    // Mark as seen
    if ($fixture_id) {
      $seen_ids[$fixture_id] = true;
    }

    // Add isToday flag for frontend styling
    $game['isToday'] = $isToday;

    // Game is actually in play right now → live array
    if ($isToday && in_array($status, $in_play_statuses)) {
      // blwp_log("  → Moving to LIVE (in-play status: {$status})");
      $live[] = $game;
    }
    // Today's finished game → keep in live array ("half-life" until midnight)
    elseif ($isToday && in_array($status, $finished_statuses)) {
      // blwp_log("  → Moving to LIVE (today, finished - half-life until midnight)");
      $live[] = $game;
    }
    // Today's game but not started yet → fixtures with isToday flag
    elseif ($isToday && in_array($status, $not_started_statuses)) {
      // blwp_log("  → Keeping in FIXTURES (today, not started)");
      $fixtures[] = $game;
    }
    // Future games → fixtures
    else {
      $fixtures[] = $game;
    }
  }

  // Process results (recent games) - skip any already seen in fixtures
  foreach ($results_raw as $game) {
    $fixture_id = $game['id'] ?? null;

    // Skip if we've already seen this fixture
    if ($fixture_id && isset($seen_ids[$fixture_id])) {
      // blwp_log("Result {$fixture_id} - SKIPPED (duplicate from fixtures)");
      continue;
    }

    $gameDateTime = new DateTime($game['date']);
    $gameDateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
    $gameDate = $gameDateTime->format('Y-m-d');
    $status = $game['status']['short'] ?? 'FT';
    $isToday = ($gameDate === $today);

    // blwp_log("Result {$fixture_id} - Date: {$game['date']}, Parsed: {$gameDate}, Status: {$status}");

    // Mark as seen
    if ($fixture_id) {
      $seen_ids[$fixture_id] = true;
    }

    // Add isToday flag for frontend styling
    $game['isToday'] = $isToday;

    // Game is actually in play right now → live array
    if ($isToday && in_array($status, $in_play_statuses)) {
      // blwp_log("  → Moving to LIVE (in-play status: {$status})");
      $live[] = $game;
    }
    // Today's finished game → keep in live array ("half-life" until midnight)
    elseif ($isToday && in_array($status, $finished_statuses)) {
      // blwp_log("  → Keeping in LIVE (today, finished - half-life until midnight)");
      $live[] = $game;
    }
    // Past finished games → results
    elseif (in_array($status, $finished_statuses)) {
      $results[] = $game;
    }
    // Other statuses (postponed, cancelled) → still in results with status
    else {
      $results[] = $game;
    }
  }

  blwp_log("Categorization complete - Live: " . count($live) . ", Fixtures: " . count($fixtures) . ", Results: " . count($results) . " (Seen IDs: " . count($seen_ids) . ")");

  return [
    'live' => $live,
    'fixtures' => $fixtures,
    'results' => $results
  ];
}
