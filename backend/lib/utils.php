<?php

require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/notify.php';

/**
 * Returns the current season based on the current date.
 *
 * @return int The current season in the format YYYY.
 * The season runs from July 1st to June 30th, so if the current month is before July,
 * the season is the previous year.
 */
function getCurrentSeason()
{
  $year = date('Y');
  $month = date('n'); // 1-12
  return ($month >= 7) ? $year : ($year - 1);
}

/**
 * Team name corrections mapping by team ID (O(1) lookup)
 * Fixes API Football team name inconsistencies and provides preferred German names
 * @return array Team ID => Corrected Name mapping
 */
function getTeamNameCorrections() {
    return [
        192 => '1. FC Köln',           // API: "1.FC Köln"
        163 => 'Bor. Mönchengladbach', // API: "Borussia Mönchengladbach" (shortened)
        164 => '1. FSV Mainz 05',      // API: "FSV Mainz 05"
        182 => '1. FC Union Berlin',   // API: "Union Berlin"
        168 => 'Bayer 04 Leverkusen'   // API: "Bayer Leverkusen"
    ];
}

/**
 * Apply team name corrections if available
 * @param array $team Team data with id and name
 * @return array Team data with corrected name
 */
function correctTeamName($team) {
    static $corrections = null;

    // Cache corrections array (loaded once per request)
    if ($corrections === null) {
        $corrections = getTeamNameCorrections();
    }

    // Apply correction if team ID exists in mapping
    if (isset($corrections[$team['id']])) {
        $team['name'] = $corrections[$team['id']];
    }

    return $team;
}

/**
 * Translate API Football round names to German equivalents
 * @param string $round Original round string from API
 * @param int $league_id League ID to determine specific formatting
 * @return string Translated German round name
 */
function translateRound($round, $league_id) {
    // Bundesliga (78) - keep specific matchday numbers
    if ($league_id === 78) {
        if (preg_match('/Regular Season - (\d+)/', $round, $matches)) {
            return $matches[1] . '. Spieltag';
        }
        if ($round === 'Relegation Round') {
            return 'Relegation';
        }
    }

    // DFB Pokal and other competitions - generic translations
    $translations = [
        // DFB Pokal
        '1st Round' => 'Runde 1',
        '2nd Round' => 'Runde 2',
        'Round of 16' => 'Achtelfinale',
        'Quarter-finals' => 'Viertelfinale',
        'Semi-finals' => 'Halbfinale',
        'Final' => 'Finale',
        '8th Finals' => 'Achtelfinale',

        // Champions League / Europa League
        '1st Qualifying Round' => 'Qualifikation',
        '2nd Qualifying Round' => 'Qualifikation',
        '3rd Qualifying Round' => 'Qualifikation',
        'Play-offs' => 'Playoffs',
        'Knockout Round Play-offs' => 'Playoffs',

        // Friendlies - all variations become generic
        'Club Friendlies' => 'Freundschaftsspiel',
        'Club Friendlies 1' => 'Freundschaftsspiel',
        'Club Friendlies 2' => 'Freundschaftsspiel',
        'Club Friendlies 3' => 'Freundschaftsspiel',
        'Club Friendlies 4' => 'Freundschaftsspiel',
        'Club Friendlies 5' => 'Freundschaftsspiel',

        // Other
        'Relegation Round' => 'Relegation'
    ];

    // Check direct translations first
    if (isset($translations[$round])) {
        return $translations[$round];
    }

    // Pattern-based translations
    if (preg_match('/League Stage - \d+/', $round)) {
        return 'Ligaphase';
    }

    if (preg_match('/Group Stage - \d+/', $round)) {
        return 'Gruppenphase';
    }

    if (preg_match('/Club Friendlies/', $round)) {
        return 'Freundschaftsspiel';
    }

    // Fallback to original
    return $round;
}

/**
 * Short status codes from api-sports.io that mean the match is over.
 *
 * This is the single source of truth for the status vocabulary. `categorizeGames()`,
 * `calculateStandings()` and the published `status.phase` all derive from these helpers,
 * and the frontend reads `phase` rather than keeping its own copy of the list.
 *
 * @return string[]
 */
function getFinishedStatuses(): array
{
  return ['FT', 'AET', 'PEN', 'AWD', 'WO'];
}

/**
 * Short status codes that mean the match is being played right now.
 *
 * @return string[]
 */
function getInPlayStatuses(): array
{
  return ['1H', 'HT', '2H', 'ET', 'P', 'BT', 'LIVE', 'INT', 'SUSP'];
}

/**
 * Short status codes whose score counts towards the league table.
 *
 * Finished ∪ in-play. A goal scored in the 80th minute has to reach the table
 * immediately, which is the whole reason the table is computed locally instead of
 * fetched from the API.
 *
 * @return string[]
 */
function getCountableStatuses(): array
{
  return array_merge(getFinishedStatuses(), getInPlayStatuses());
}

/**
 * Classify a status code into a coarse phase the UI can branch on.
 *
 * @param string|null $status Short status code from api-sports.io
 * @return string 'finished' | 'live' | 'upcoming' | 'other'
 */
function classifyPhase($status): string
{
  if (in_array($status, getFinishedStatuses(), true)) {
    return 'finished';
  }
  if (in_array($status, getInPlayStatuses(), true)) {
    return 'live';
  }
  if (in_array($status, ['TBD', 'NS'], true)) {
    return 'upcoming';
  }

  // PST, CANC, ABD and anything new the API introduces
  return 'other';
}

function filterFixtures(array $fixtures): array
{
  return array_map(function ($item) {
    return [
      'id' => $item['fixture']['id'],
      'date' => $item['fixture']['date'],
      'status' =>  [
        'short' => $item['fixture']['status']['short'],
        'long' => $item['fixture']['status']['long'],
        'elapsed' => $item['fixture']['status']['elapsed'] ?? null,
        'extra' => $item['fixture']['status']['extra'] ?? null,
        // Coarse classification, resolved once here so no consumer has to keep its own
        // copy of the status vocabulary. See classifyPhase().
        'phase' => classifyPhase($item['fixture']['status']['short'])
      ],
      'league' => [
        'id' => $item['league']['id'],
        'name' => $item['league']['name'],
        'logo' => $item['league']['logo'],
        'round' => translateRound($item['league']['round'], $item['league']['id'])
      ],
      'teams' => [
        'home' => correctTeamName([
          'id' => $item['teams']['home']['id'],
          'name' => $item['teams']['home']['name'],
          'logo' => $item['teams']['home']['logo'],
          'winner' => $item['teams']['home']['winner']
        ]),
        'away' => correctTeamName([
          'id' => $item['teams']['away']['id'],
          'name' => $item['teams']['away']['name'],
          'logo' => $item['teams']['away']['logo'],
          'winner' => $item['teams']['away']['winner']
        ])
      ],
      'goals' => $item['goals'],
      'score' => [
        'halftime' => $item['score']['halftime'] ?? null,
        'fulltime' => $item['score']['fulltime'] ?? null,
        'extratime' => $item['score']['extratime'] ?? null,
        'penalty' => $item['score']['penalty'] ?? null
      ]
    ];
  }, $fixtures['response'] ?? []);
}


/**
 * Given an API Football standings response, filters and returns only the relevant fields.
 *
 * @param array $standings
 * @param boolean $keep_all_fields
 * @return array
 */
function filterStandings(array $standings, bool $keep_all_fields = false): array
{
  $filtered = [];
  if (!empty($standings['response'][0]['league']['standings'][0])) {
    foreach ($standings['response'][0]['league']['standings'][0] as $team) {
      if ($keep_all_fields) {
        // Keep all fields for caching/merging - apply team name correction
        $team['team'] = correctTeamName($team['team']);
        $filtered[] = $team;
      } else {
        // Legacy filtering for minimal response (backward compatibility)
        $corrected_team = correctTeamName($team['team']);
        $filtered[] = [
          'rank' => $team['rank'],
          'team' => [
            'id' => $corrected_team['id'],
            'name' => $corrected_team['name'],
            'logo' => $corrected_team['logo']
          ],
          'points' => $team['points'],
          'goalsDiff' => $team['goalsDiff'],
          'played' => $team['all']['played'],
          'wins' => $team['all']['win'],
          'draws' => $team['all']['draw'],
          'losses' => $team['all']['lose'],
        ];
      }
    }
  }
  return $filtered;
}

/**
 * Calculate live standings from all fixtures (finished + in-play)
 *
 * @param array $all_fixtures All fixtures from the API (include past, present, future)
 * @param int $competition_id The competition/league ID
 * @return array Standings table sorted by points, goal difference, goals scored
 */
function calculateStandings(array $all_fixtures, int $competition_id): array
{
  $teams = [];

  // Finished games plus games in progress — a goal has to reach the table immediately.
  $countable_statuses = getCountableStatuses();

  // Process fixtures with countable statuses
  foreach ($all_fixtures['response'] ?? [] as $fixture) {
    $status = $fixture['fixture']['status']['short'] ?? '';

    // Only count matches from the specified competition with countable status
    if (
      !in_array($status, $countable_statuses, true) ||
      $fixture['league']['id'] !== $competition_id
    ) {
      continue;
    }

    $home_id = $fixture['teams']['home']['id'];
    $away_id = $fixture['teams']['away']['id'];
    $home_goals = $fixture['goals']['home'] ?? 0;
    $away_goals = $fixture['goals']['away'] ?? 0;

    // Initialize team data if not exists
    if (!isset($teams[$home_id])) {
      $corrected_home_team = correctTeamName($fixture['teams']['home']);
      $teams[$home_id] = [
        'team' => [
          'id' => $home_id,
          'name' => $corrected_home_team['name'],
          'logo' => $corrected_home_team['logo']
        ],
        'points' => 0,
        'goalsDiff' => 0,
        'group' => 'Bundesliga',
        'all' => [
          'played' => 0,
          'win' => 0,
          'draw' => 0,
          'lose' => 0,
          'goals' => ['for' => 0, 'against' => 0]
        ],
        'home' => [
          'played' => 0,
          'win' => 0,
          'draw' => 0,
          'lose' => 0,
          'goals' => ['for' => 0, 'against' => 0]
        ],
        'away' => [
          'played' => 0,
          'win' => 0,
          'draw' => 0,
          'lose' => 0,
          'goals' => ['for' => 0, 'against' => 0]
        ]
      ];
    }

    if (!isset($teams[$away_id])) {
      $corrected_away_team = correctTeamName($fixture['teams']['away']);
      $teams[$away_id] = [
        'team' => [
          'id' => $away_id,
          'name' => $corrected_away_team['name'],
          'logo' => $corrected_away_team['logo']
        ],
        'points' => 0,
        'goalsDiff' => 0,
        'group' => 'Bundesliga',
        'all' => [
          'played' => 0,
          'win' => 0,
          'draw' => 0,
          'lose' => 0,
          'goals' => ['for' => 0, 'against' => 0]
        ],
        'home' => [
          'played' => 0,
          'win' => 0,
          'draw' => 0,
          'lose' => 0,
          'goals' => ['for' => 0, 'against' => 0]
        ],
        'away' => [
          'played' => 0,
          'win' => 0,
          'draw' => 0,
          'lose' => 0,
          'goals' => ['for' => 0, 'against' => 0]
        ]
      ];
    }

    // Update home team stats
    $teams[$home_id]['all']['played']++;
    $teams[$home_id]['all']['goals']['for'] += $home_goals;
    $teams[$home_id]['all']['goals']['against'] += $away_goals;
    $teams[$home_id]['home']['played']++;
    $teams[$home_id]['home']['goals']['for'] += $home_goals;
    $teams[$home_id]['home']['goals']['against'] += $away_goals;

    // Update away team stats
    $teams[$away_id]['all']['played']++;
    $teams[$away_id]['all']['goals']['for'] += $away_goals;
    $teams[$away_id]['all']['goals']['against'] += $home_goals;
    $teams[$away_id]['away']['played']++;
    $teams[$away_id]['away']['goals']['for'] += $away_goals;
    $teams[$away_id]['away']['goals']['against'] += $home_goals;

    // Determine result and update points/record
    if ($home_goals > $away_goals) {
      // Home win
      $teams[$home_id]['all']['win']++;
      $teams[$home_id]['home']['win']++;
      $teams[$home_id]['points'] += 3;
      $teams[$away_id]['all']['lose']++;
      $teams[$away_id]['away']['lose']++;
    } elseif ($home_goals < $away_goals) {
      // Away win
      $teams[$away_id]['all']['win']++;
      $teams[$away_id]['away']['win']++;
      $teams[$away_id]['points'] += 3;
      $teams[$home_id]['all']['lose']++;
      $teams[$home_id]['home']['lose']++;
    } else {
      // Draw
      $teams[$home_id]['all']['draw']++;
      $teams[$home_id]['home']['draw']++;
      $teams[$home_id]['points']++;
      $teams[$away_id]['all']['draw']++;
      $teams[$away_id]['away']['draw']++;
      $teams[$away_id]['points']++;
    }

    // Update goal difference
    $teams[$home_id]['goalsDiff'] = $teams[$home_id]['all']['goals']['for'] - $teams[$home_id]['all']['goals']['against'];
    $teams[$away_id]['goalsDiff'] = $teams[$away_id]['all']['goals']['for'] - $teams[$away_id]['all']['goals']['against'];
  }

  // Convert to indexed array and sort
  $standings = array_values($teams);

  // Sort by: 1) Points DESC, 2) Goal Diff DESC, 3) Goals For DESC
  usort($standings, function ($a, $b) {
    if ($a['points'] !== $b['points']) {
      return $b['points'] - $a['points'];
    }
    if ($a['goalsDiff'] !== $b['goalsDiff']) {
      return $b['goalsDiff'] - $a['goalsDiff'];
    }
    return $b['all']['goals']['for'] - $a['all']['goals']['for'];
  });

  // Add rank
  foreach ($standings as $index => &$team) {
    $team['rank'] = $index + 1;
  }

  return $standings;
}

/**
 * Merge calculated standings with API standings to get additional fields
 * like form, status, description that don't need real-time calculation
 *
 * @param array $calculated_standings Our real-time calculated standings
 * @param array $api_standings API Football standings response
 * @return array Merged standings with real-time core data + API metadata
 */
function mergeStandingsWithAPI(array $calculated_standings, array $api_standings): array
{
  // Index API standings by team ID for quick lookup (form, status, update)
  $api_by_team = [];
  // Build rank -> description map: zone boundaries (CL, EL, Relegation, etc.)
  // are positional and don't change mid-season, so description must follow
  // the new calculated rank — not the team's old cached position.
  $api_description_by_rank = [];
  foreach ($api_standings as $api_team) {
    $team_id = $api_team['team']['id'];
    $api_by_team[$team_id] = $api_team;
    $api_description_by_rank[$api_team['rank']] = $api_team['description'] ?? null;
  }

  // Merge: Use our calculated core stats, add API metadata
  $merged = [];
  foreach ($calculated_standings as $calc_team) {
    $team_id = $calc_team['team']['id'];
    $merged_team = $calc_team; // Start with our calculated data

    // Add team-specific API metadata (form, status, update)
    if (isset($api_by_team[$team_id])) {
      $api_team = $api_by_team[$team_id];
      $merged_team['form'] = $api_team['form'] ?? null;
      $merged_team['status'] = $api_team['status'] ?? null;
      $merged_team['update'] = $api_team['update'] ?? null;
    }

    // description is rank/zone-based, not team-based: assign by new calculated rank
    // so it updates immediately when teams swap positions during/after a match
    $merged_team['description'] = $api_description_by_rank[$calc_team['rank']] ?? null;

    $merged[] = $merged_team;
  }

  return $merged;
}

/**
 * Validate calculated standings against API standings
 * Logs discrepancies for debugging
 *
 * @param array $calculated_standings Our calculated standings
 * @param array $api_standings API Football standings
 * @param string $domain Domain name for logging context
 * @return array Validation results with any discrepancies found
 */
function validateStandings(array $calculated_standings, array $api_standings, string $domain): array
{
  $discrepancies = [];

  // Index both by team ID
  $calc_by_team = [];
  foreach ($calculated_standings as $calc_team) {
    $calc_by_team[$calc_team['team']['id']] = $calc_team;
  }

  $api_by_team = [];
  foreach ($api_standings as $api_team) {
    $api_by_team[$api_team['team']['id']] = $api_team;
  }

  // Compare each team
  foreach ($calc_by_team as $team_id => $calc_team) {
    if (!isset($api_by_team[$team_id])) {
      $discrepancies[] = [
        'team' => $calc_team['team']['name'],
        'issue' => 'Team in calculated standings but not in API standings'
      ];
      continue;
    }

    $api_team = $api_by_team[$team_id];
    $team_name = $calc_team['team']['name'];

    // Compare critical fields
    $checks = [
      'points' => [$calc_team['points'], $api_team['points']],
      'goalsDiff' => [$calc_team['goalsDiff'], $api_team['goalsDiff']],
      'played' => [$calc_team['all']['played'], $api_team['all']['played']],
      'wins' => [$calc_team['all']['win'], $api_team['all']['win']],
      'draws' => [$calc_team['all']['draw'], $api_team['all']['draw']],
      'losses' => [$calc_team['all']['lose'], $api_team['all']['lose']],
      'goalsFor' => [$calc_team['all']['goals']['for'], $api_team['all']['goals']['for']],
      'goalsAgainst' => [$calc_team['all']['goals']['against'], $api_team['all']['goals']['against']]
    ];

    foreach ($checks as $field => $values) {
      list($calc_val, $api_val) = $values;
      if ($calc_val !== $api_val) {
        $discrepancies[] = [
          'team' => $team_name,
          'field' => $field,
          'calculated' => $calc_val,
          'api' => $api_val
        ];
      }
    }
  }

  // Log results
  if (empty($discrepancies)) {
    // Verbose: a passing validation is the expected outcome, and the old version wrote a line
    // every time the interval elapsed even though nothing was wrong.
    blwp_log_verbose("✓ Standings validation passed for {$domain} - all data matches API");
  } else {
    blwp_log("✗ Standings validation failed for {$domain} - found " . count($discrepancies) . " discrepancies:");

    foreach ($discrepancies as $disc) {
      if (isset($disc['field'])) {
        blwp_log("  {$disc['team']}: {$disc['field']} = {$disc['calculated']} (calc) vs {$disc['api']} (API)");
      } else {
        blwp_log("  {$disc['team']}: {$disc['issue']}");
      }
    }

    // Worth an alert: the published table is always the locally calculated one, so this is
    // not an outage — but a mismatch is usually the first sign that the API changed something
    // underneath us, and nobody is watching this log.
    $examples = array_slice($discrepancies, 0, 5);
    $lines = array_map(
      fn($disc) => isset($disc['field'])
        ? "{$disc['team']}: {$disc['field']} — calculated {$disc['calculated']}, API {$disc['api']}"
        : "{$disc['team']}: {$disc['issue']}",
      $examples
    );

    blwp_notify(
      "Standings cross-check found " . count($discrepancies) . " discrepancy(ies) between the\n"
        . "locally calculated table and the API.\n\n" . implode("\n", $lines)
        . (count($discrepancies) > count($examples) ? "\n…" : '')
        . "\n\nThis is a warning, not an outage: the calculated table is still what gets published.",
      'validation'
    );
  }

  return [
    'valid' => empty($discrepancies),
    'discrepancies' => $discrepancies
  ];
}
