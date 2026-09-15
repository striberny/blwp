<?php

class APIFootball
{

  private $api_key;
  private $base_url = 'https://v3.football.api-sports.io/';

  public function __construct(string $api_key)
  {
    $this->api_key = $api_key;
    if (empty($this->api_key)) {
      throw new InvalidArgumentException('API key is required');
    }
  }

  private function fetch(string $endpoint, array $params): array
  {

    $url = $this->base_url . $endpoint . '?' . http_build_query($params);

    $ch = curl_init();

    curl_setopt_array($ch, array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_HTTPHEADER => array(
        'x-apisports-key: ' . $this->api_key,
      ),
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode !== 200 || !$response) {
      // TODO: Später Exception einbauen
      return ['error' => 'API call failed', 'status' => $httpCode];
    }
    // curl_close($ch);

    return json_decode($response, true);
  }

  /**
   * Holt die nächsten Spiele.
   */
  public function getFixtures(int $teamId, int $limitNext = 5): array
  {
    return $this->fetch('/fixtures', [
      'team' => $teamId,
      'next' => $limitNext,
      'timezone' => 'Europe/Berlin'
    ]);
  }

  /**
   * Holt die letzten Ergebnisse.
   */
  public function getResults(int $teamId, int $limitLast = 5): array
  {
    return $this->fetch('/fixtures', [
      'team' => $teamId,
      'last' => $limitLast,
      'timezone' => 'Europe/Berlin'
    ]);
  }

  /**
   * Holt die Bundesliga-Tabelle.
   */
  public function getStandings(int $leagueId, int $season): array
  {
    return $this->fetch('/standings', [
      'league' => $leagueId,
      'season' => $season
    ]);
  }

  /**
   * Holt alle Saisonspiele für eine Liga (für Tabellenberechnung).
   */
  public function getLeagueFixtures(int $leagueId, int $season): array
  {
    return $this->fetch('/fixtures', [
      'league' => $leagueId,
      'season' => $season,
      'timezone' => 'Europe/Berlin'
    ]);
  }

  public function getFixturesLive(int $teamId): array
  {
    return $this->fetch('/fixtures', [
      'team' => $teamId,
      'live' => 'all',
      'timezone' => 'Europe/Berlin'
    ]);
  }

  /**
   * Holt alle Spiele eines Teams an einem bestimmten Datum (unabhängig vom Status).
   * Bridging the gap: in-play games may not appear in 'next' or 'last' endpoints.
   */
  public function getFixturesByDate(int $teamId, string $date): array
  {
    return $this->fetch('/fixtures', [
      'team' => $teamId,
      'date' => $date,
      'timezone' => 'Europe/Berlin'
    ]);
  }

  /**
   * Holt alle Saisonspiele für ein Team (alle Wettbewerbe).
   */
  public function getTeamSeasonFixtures(int $teamId, int $season): array
  {
    return $this->fetch('/fixtures', [
      'team' => $teamId,
      'season' => $season,
      'timezone' => 'Europe/Berlin'
    ]);
  }
}
