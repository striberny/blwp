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
 * @return array Result with success status and details
 */
function fetch_site_data($domain, $config)
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

  // Always derive the season from the calendar (getCurrentSeason). site-mapping.json's
  // `season` is only ever whatever the plugin last sent, the plugin has no UI for it, so
  // trusting it pinned the value to one year — which made {domain}.json disagree with
  // standings.json, since that one uses default_season.
  $season = $config['default_season'];
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
        // Bumped when the published shape changes in a breaking way. The plugin checks
        // this and warns, instead of silently rendering blanks after a field rename.
        // 2 = fixtures carry status.phase (added in the phase refactor)
        'schema' => 2,
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

  // The standings validation interval is the only remaining cadence. The API standings are
  // fetched on every tick now; only the local-vs-API cross-check runs less often, so a
  // healthy system produces no log output. See docs/architecture.md.
  $intervals = $config['intervals'] ?? [];
  $validation_interval = $intervals['standings_validation'] ?? 60;

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
    $live_statuses = getInPlayStatuses();
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

    // Always refresh the API standings. Against the plan the quota cost is negligible, and
    // it keeps the merged metadata (form, status, description) fresh within one tick rather
    // than lagging up to an hour behind the locally calculated table.
    $api_standings_raw = $api->getStandings($competition_id, $season);
    $api_standings = filterStandings($api_standings_raw, true);

    if (empty($api_standings)) {
      // Upstream returned nothing usable. Reuse the last good payload rather than letting
      // form/status/description silently vanish from the table.
      $api_standings = $cron_state['global']['api_standings_cache'] ?? [];
      blwp_log('WARNING - getStandings() returned no rows; reusing the cached metadata');
    } else {
      $cron_state['global']['api_standings_cache'] = $api_standings;
    }

    // validateStandings() is a cross-check, not a dependency — the table is calculated
    // locally either way. Run it on its own slower cadence so a healthy system stays quiet.
    $last_validation = $cron_state['global']['last_standings_validation'] ?? null;
    $minutes_since_validation = $last_validation ? (time() - strtotime($last_validation)) / 60 : 9999;

    if (!empty($api_standings) && $minutes_since_validation >= $validation_interval) {
      validateStandings($calculated_standings, $api_standings, 'global');
      $cron_state['global']['last_standings_validation'] = date('c');
    }

    // Merge calculated + API metadata
    $global_standings = !empty($api_standings)
      ? mergeStandingsWithAPI($calculated_standings, $api_standings)
      : $calculated_standings;

    // Save global standings.json
    $standings_file = $data_dir . '/standings.json';
    $standings_data = [
      'meta' => [
        'schema' => 2,
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

  // Each bucket keeps its own dedupe guard. This used to be a single shared "seen" set,
  // which forced every game into exactly one bucket — and that silently kept every match
  // finished *today* out of `results` until midnight. The most recent result was therefore
  // missing from the Ergebnisse tab and from the form widget, which is precisely the match
  // a user had just watched.
  $seen = ['live' => [], 'fixtures' => [], 'results' => []];

  /**
   * Place a game into one bucket, ignoring it if that bucket already holds this fixture.
   * A fixture may legitimately belong to two buckets at once — see the half-life below.
   */
  $add = function (string $bucket, array $game) use (&$live, &$fixtures, &$results, &$seen) {
    $id = $game['id'] ?? null;

    if ($id !== null && isset($seen[$bucket][$id])) {
      return;
    }
    if ($id !== null) {
      $seen[$bucket][$id] = true;
    }

    if ($bucket === 'live') {
      $live[] = $game;
    } elseif ($bucket === 'fixtures') {
      $fixtures[] = $game;
    } else {
      $results[] = $game;
    }
  };

  // Process fixtures first: upcoming games, plus everything happening today. Today's games
  // arrive twice (once via getFixturesByDate, once via getFixtures) — the per-bucket guards
  // now do the deduplication a global set used to handle.
  foreach ($fixtures_raw as $game) {
    $gameDateTime = new DateTime($game['date']);
    $gameDateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
    $isToday = ($gameDateTime->format('Y-m-d') === $today);

    // filterFixtures() already classified this; fall back for safety.
    $phase = $game['status']['phase'] ?? classifyPhase($game['status']['short'] ?? '');

    // Add isToday flag for frontend styling
    $game['isToday'] = $isToday;

    if ($isToday && $phase === 'live') {
      // In play right now
      $add('live', $game);
    } elseif ($isToday && $phase === 'finished') {
      // Finished today — two buckets on purpose:
      //   `live`    → the "half-life" rule, so the game does not vanish from the
      //               Spielplan tab the moment the final whistle blows
      //   `results` → so the Ergebnisse tab and the form widget see it straight away
      //               instead of having to wait until midnight
      $add('live', $game);
      $add('results', $game);
    } else {
      // Future games, and today's games that have not kicked off yet (isToday = true)
      $add('fixtures', $game);
    }
  }

  // Then recent results. The loop above already published today's finished matches, and
  // because it ran first they sit ahead of these older ones — so `results` stays ordered
  // newest-first, which is the order the frontend slices.
  foreach ($results_raw as $game) {
    $gameDateTime = new DateTime($game['date']);
    $gameDateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
    $isToday = ($gameDateTime->format('Y-m-d') === $today);

    // filterFixtures() already classified this; fall back for safety.
    $phase = $game['status']['phase'] ?? classifyPhase($game['status']['short'] ?? '');

    // Add isToday flag for frontend styling
    $game['isToday'] = $isToday;

    if ($isToday && $phase === 'live') {
      // Rare, but the API can report an in-play match here. `live` is owned by the
      // fixtures loop, so this only ever fills a gap.
      $add('live', $game);
    } else {
      // Finished, postponed or cancelled — all belong in results.
      $add('results', $game);
    }
  }

  $distinct = count(array_unique(array_merge(
    array_keys($seen['live']),
    array_keys($seen['fixtures']),
    array_keys($seen['results'])
  )));

  blwp_log(
    "Categorization complete - Live: " . count($live) .
    ", Fixtures: " . count($fixtures) .
    ", Results: " . count($results) .
    " (Distinct fixtures: {$distinct})"
  );

  return [
    'live' => $live,
    'fixtures' => $fixtures,
    'results' => $results
  ];
}
