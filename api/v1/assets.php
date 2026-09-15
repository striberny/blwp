<?php
/**
 * Assets Endpoint
 * Returns list of all team and league logos needed for a site
 *
 * Modes:
 * 1. GET /v1/assets.php?team=157&season=2024 - Scan team fixtures (recommended)
 * 2. GET /v1/assets.php?league=78&season=2024 - Get league teams only (legacy)
 */

// Locate bootstrap based on environment
if (file_exists('/home/deploy/blwp/bootstrap.php')) {
    require_once '/home/deploy/blwp/bootstrap.php';
} else {
    require_once __DIR__ . '/../../backend/bootstrap.php';
}

header('Content-Type: application/json');

require_once BLWP_INC_DIR . '/APIFootball.php';

$config = require BLWP_CONFIG_DIR . '/api-config.php';

// Get parameters
$team_id = isset($_GET['team']) ? (int) $_GET['team'] : null;
$league_id = isset($_GET['league']) ? (int) $_GET['league'] : 78;
$season = isset($_GET['season']) ? (int) $_GET['season'] : $config['default_season'];

try {
  $api = new APIFootball($config['api_key']);

  $teams = [];
  $leagues = [];

  // Mode 1: Scan team fixtures (gets all competitions and opponents)
  if ($team_id) {
    $fixtures_data = fetchAssetsFromTeamFixtures($api, $team_id, $season);
    $teams = $fixtures_data['teams'];
    $leagues = $fixtures_data['leagues'];

    // Also fetch the main league standings for complete team list
    $standings_data = fetchTeamsForLeague($api, $league_id, $season);
    $teams = array_merge($teams, $standings_data['teams']);
    $leagues = array_merge($leagues, $standings_data['leagues']);
  }
  // Mode 2: Legacy - just fetch league teams
  else {
    $standings_data = fetchTeamsForLeague($api, $league_id, $season);
    $teams = $standings_data['teams'];
    $leagues = $standings_data['leagues'];
  }

  // Remove duplicates by ID
  $teams = array_values(array_column_unique($teams, 'id'));
  $leagues = array_values(array_column_unique($leagues, 'id'));

  echo json_encode([
    'success' => true,
    'data' => [
      'teams' => $teams,
      'leagues' => $leagues,
      'meta' => [
        'team_id' => $team_id,
        'league_id' => $league_id,
        'season' => $season,
        'teams_count' => count($teams),
        'leagues_count' => count($leagues),
      ]
    ]
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ]);
}

/**
 * Scan team fixtures to extract all teams and leagues
 * This catches Champions League, DFB-Pokal, and other competitions
 */
function fetchAssetsFromTeamFixtures($api, $team_id, $season) {
  $teams = [];
  $leagues = [];
  $seen_teams = [];
  $seen_leagues = [];

  // Get all fixtures for the team this season
  $fixtures_response = $api->getTeamSeasonFixtures($team_id, $season);

  if (isset($fixtures_response['response']) && is_array($fixtures_response['response'])) {
    foreach ($fixtures_response['response'] as $fixture) {
      // Extract league
      $league = $fixture['league'];
      if (!isset($seen_leagues[$league['id']])) {
        $seen_leagues[$league['id']] = true;
        $leagues[] = [
          'id' => $league['id'],
          'name' => $league['name'],
          'logo' => $league['logo'],
          'country' => $league['country'] ?? null,
        ];
      }

      // Extract home team
      $home = $fixture['teams']['home'];
      if (!isset($seen_teams[$home['id']])) {
        $seen_teams[$home['id']] = true;
        $teams[] = [
          'id' => $home['id'],
          'name' => $home['name'],
          'logo' => $home['logo'],
        ];
      }

      // Extract away team
      $away = $fixture['teams']['away'];
      if (!isset($seen_teams[$away['id']])) {
        $seen_teams[$away['id']] = true;
        $teams[] = [
          'id' => $away['id'],
          'name' => $away['name'],
          'logo' => $away['logo'],
        ];
      }
    }
  }

  return ['teams' => $teams, 'leagues' => $leagues];
}

/**
 * Fetch teams and league info for a specific league/season
 */
function fetchTeamsForLeague($api, $league_id, $season) {
  $teams = [];
  $leagues = [];

  // Fetch teams via standings (gives us all teams + league info)
  $standings_response = $api->getStandings($league_id, $season);

  if (isset($standings_response['response'][0])) {
    $league_data = $standings_response['response'][0]['league'];

    // Add league logo
    $leagues[] = [
      'id' => $league_data['id'],
      'name' => $league_data['name'],
      'logo' => $league_data['logo'],
      'country' => $league_data['country'] ?? null,
    ];

    // Extract teams from standings
    if (isset($league_data['standings'][0])) {
      foreach ($league_data['standings'][0] as $standing) {
        $team = $standing['team'];
        $teams[] = [
          'id' => $team['id'],
          'name' => $team['name'],
          'logo' => $team['logo'],
        ];
      }
    }
  }

  return ['teams' => $teams, 'leagues' => $leagues];
}

/**
 * Remove duplicates from array by a specific key
 */
function array_column_unique($array, $key) {
  $seen = [];
  $result = [];

  foreach ($array as $item) {
    if (!isset($seen[$item[$key]])) {
      $seen[$item[$key]] = true;
      $result[] = $item;
    }
  }

  return $result;
}
