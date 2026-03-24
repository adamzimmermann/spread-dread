<?php

namespace App\Service;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Team;
use App\Repository\GameRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EspnApiService
{
    private const SCOREBOARD_URL = 'https://site.api.espn.com/apis/site/v2/sports/basketball/mens-college-basketball/scoreboard';
    private const SUMMARY_URL = 'https://site.api.espn.com/apis/site/v2/sports/basketball/mens-college-basketball/summary';

    private const SEED_MATCHUPS = [
        [1, 16], [8, 9], [5, 12], [4, 13],
        [6, 11], [3, 14], [7, 10], [2, 15],
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private GameRepository $gameRepository,
        private TeamRepository $teamRepository,
    ) {
    }

    /**
     * Populate a bracket's R64 games with teams fetched from ESPN.
     * Creates Team entities and assigns them to games with ESPN event IDs.
     * @return array{success: bool, count: int, error?: string}
     */
    public function populateBracketTeams(Bracket $bracket): array
    {
        $result = $this->fetchTournamentTeams($bracket->getYear());

        if (!empty($result['error'])) {
            return ['success' => false, 'count' => 0, 'error' => $result['error']];
        }

        $teams = $result['teams'] ?? [];
        if (empty($teams)) {
            return ['success' => false, 'count' => 0, 'error' => 'No tournament teams found'];
        }

        // Create Team entities
        $teamsByRegion = [];
        foreach ($teams as $teamData) {
            $existing = $this->teamRepository->findOneBy([
                'year' => $bracket->getYear(),
                'region' => $teamData['region'],
                'seed' => $teamData['seed'],
            ]);

            if ($existing) {
                $existing->setName($teamData['name']);
                $existing->setApiName($teamData['name']);
                $team = $existing;
            } else {
                $team = new Team();
                $team->setName($teamData['name']);
                $team->setApiName($teamData['name']);
                $team->setSeed($teamData['seed']);
                $team->setRegion($teamData['region']);
                $team->setYear($bracket->getYear());
                $this->em->persist($team);
            }

            $teamsByRegion[$teamData['region']][$teamData['seed']] = $team;
        }

        $this->em->flush();

        // Index matchups by region+seeds for event ID lookup
        $matchupIndex = [];
        foreach ($result['matchups'] ?? [] as $m) {
            $matchupIndex[$m['region'] . '_' . $m['seed1'] . '_' . $m['seed2']] = $m['event_id'];
        }

        // Assign teams and event IDs to R64 games
        $r64Games = $this->gameRepository->findByBracketAndRound($bracket, 1);
        foreach ($r64Games as $game) {
            $region = $game->getRegion();
            $pos = $game->getBracketPosition();
            $matchup = self::SEED_MATCHUPS[$pos - 1];

            if (isset($teamsByRegion[$region][$matchup[0]])) {
                $game->setTeam1($teamsByRegion[$region][$matchup[0]]);
            }
            if (isset($teamsByRegion[$region][$matchup[1]])) {
                $game->setTeam2($teamsByRegion[$region][$matchup[1]]);
            }

            $key = $region . '_' . $matchup[0] . '_' . $matchup[1];
            if (isset($matchupIndex[$key])) {
                $game->setExternalGameId($matchupIndex[$key]);
            }
        }

        $this->em->flush();

        return ['success' => true, 'count' => count($teams)];
    }

    /**
     * Fetch tournament teams from ESPN scoreboard for first-round games.
     * Returns teams with name, seed, region, and the ESPN event ID for each matchup.
     * @return array{teams: array, matchups: array, error?: string}
     */
    public function fetchTournamentTeams(int $year): array
    {
        $events = $this->fetchFirstRoundEvents($year);

        if (empty($events)) {
            return ['teams' => [], 'matchups' => [], 'error' => 'No tournament games found for ' . $year];
        }

        $teams = [];
        $matchups = [];
        $seen = [];

        foreach ($events as $event) {
            $competition = $event['competitions'][0] ?? null;
            if (!$competition) {
                continue;
            }

            $region = $this->parseRegion($competition);
            if (!$region) {
                continue;
            }

            $noteHeadline = $competition['notes'][0]['headline'] ?? '';
            if (!str_contains($noteHeadline, '1st Round')) {
                continue;
            }

            $eventId = (string) ($event['id'] ?? '');
            $seeds = [];

            foreach ($competition['competitors'] ?? [] as $competitor) {
                $seed = $competitor['curatedRank']['current'] ?? null;
                $teamData = $competitor['team'] ?? [];
                $teamName = $teamData['displayName'] ?? $teamData['shortDisplayName'] ?? '';

                if (!$seed || !$teamName) {
                    continue;
                }

                $key = $region . '_' . $seed;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $seeds[] = (int) $seed;

                $teams[] = [
                    'name' => $teamName,
                    'seed' => (int) $seed,
                    'region' => $region,
                ];
            }

            // Store matchup with event ID for later use
            if (count($seeds) === 2) {
                sort($seeds);
                $matchups[] = [
                    'event_id' => $eventId,
                    'region' => $region,
                    'seed1' => $seeds[0],
                    'seed2' => $seeds[1],
                ];
            }
        }

        usort($teams, function ($a, $b) {
            $regionOrder = ['East' => 0, 'West' => 1, 'South' => 2, 'Midwest' => 3];
            $cmp = ($regionOrder[$a['region']] ?? 99) - ($regionOrder[$b['region']] ?? 99);
            return $cmp !== 0 ? $cmp : $a['seed'] - $b['seed'];
        });

        return ['teams' => $teams, 'matchups' => $matchups];
    }

    /**
     * Pull spreads from ESPN for games in a bracket round.
     * Uses per-event summary calls to get pickcenter spread data.
     * @return array{matched: int, total: int, unmatched: array}
     */
    public function pullSpreads(Bracket $bracket, int $roundNumber): array
    {
        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);

        $matched = 0;
        $unmatched = [];

        foreach ($games as $game) {
            if ($game->getSpread() !== null) {
                continue;
            }
            if (!$game->getTeam1() || !$game->getTeam2()) {
                continue;
            }

            $eventId = $game->getExternalGameId();
            if (!$eventId) {
                // Try to find the event via scoreboard search
                $eventId = $this->findEspnEventId($game, $bracket->getYear());
                if ($eventId) {
                    $game->setExternalGameId($eventId);
                }
            }

            if (!$eventId) {
                $unmatched[] = $game->getId();
                continue;
            }

            $summary = $this->fetchEventSummary($eventId);
            if (!$summary) {
                $unmatched[] = $game->getId();
                continue;
            }

            if ($this->applySpread($game, $summary)) {
                $matched++;
            } else {
                $unmatched[] = $game->getId();
            }
        }

        $this->em->flush();

        return ['matched' => $matched, 'total' => count($games), 'unmatched' => $unmatched];
    }

    /**
     * Pull scores from ESPN scoreboard for games in a bracket round.
     * @return array{updated: int, unmatched: array}
     */
    public function updateScores(Bracket $bracket, int $roundNumber): array
    {
        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);
        $year = $bracket->getYear();

        // Fetch scoreboard events for the tournament
        $events = $this->fetchTournamentScoreboard($year);

        // Index events by ID for quick lookup
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[$event['id'] ?? ''] = $event;
        }

        $updated = 0;
        $unmatched = [];

        foreach ($games as $game) {
            if ($game->isComplete()) {
                continue;
            }
            if (!$game->getTeam1() || !$game->getTeam2()) {
                continue;
            }

            $eventId = $game->getExternalGameId();
            if (!$eventId) {
                $eventId = $this->findEspnEventId($game, $year);
                if ($eventId) {
                    $game->setExternalGameId($eventId);
                }
            }

            if (!$eventId || !isset($eventsById[$eventId])) {
                $unmatched[] = $game->getId();
                continue;
            }

            $event = $eventsById[$eventId];
            $competition = $event['competitions'][0] ?? null;
            if (!$competition) {
                $unmatched[] = $game->getId();
                continue;
            }

            $status = $competition['status']['type'] ?? [];
            if (!($status['completed'] ?? false)) {
                continue; // Game not finished yet — not an error
            }

            if ($this->applyScores($game, $competition)) {
                $updated++;
            }
        }

        $this->em->flush();

        return ['updated' => $updated, 'unmatched' => $unmatched];
    }

    private function fetchFirstRoundEvents(int $year): array
    {
        $startDate = $year . '0318';
        $endDate = $year . '0322';

        try {
            $response = $this->httpClient->request('GET', self::SCOREBOARD_URL, [
                'query' => [
                    'dates' => $startDate . '-' . $endDate,
                    'groups' => 100,
                    'limit' => 100,
                ],
            ]);
            $data = $response->toArray();
            return $data['events'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Fetch scoreboard events across the full tournament date range.
     */
    private function fetchTournamentScoreboard(int $year): array
    {
        $startDate = $year . '0318';
        $endDate = $year . '0410';

        try {
            $response = $this->httpClient->request('GET', self::SCOREBOARD_URL, [
                'query' => [
                    'dates' => $startDate . '-' . $endDate,
                    'groups' => 100,
                    'limit' => 200,
                ],
            ]);
            $data = $response->toArray();
            return $data['events'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function fetchEventSummary(string $eventId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::SUMMARY_URL, [
                'query' => ['event' => $eventId],
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find an ESPN event ID by matching team names from the scoreboard.
     */
    private function findEspnEventId(Game $game, int $year): ?string
    {
        $events = $this->fetchTournamentScoreboard($year);

        $team1Name = strtolower($game->getTeam1()->getName());
        $team2Name = strtolower($game->getTeam2()->getName());

        foreach ($events as $event) {
            $competition = $event['competitions'][0] ?? null;
            if (!$competition) {
                continue;
            }

            $eventTeams = [];
            foreach ($competition['competitors'] ?? [] as $competitor) {
                $teamData = $competitor['team'] ?? [];
                $name = strtolower($teamData['displayName'] ?? '');
                if ($name) {
                    $eventTeams[] = $name;
                }
            }

            if (in_array($team1Name, $eventTeams) && in_array($team2Name, $eventTeams)) {
                return (string) ($event['id'] ?? '');
            }
        }

        return null;
    }

    private function applySpread(Game $game, array $summary): bool
    {
        $pickcenter = $summary['pickcenter'] ?? [];
        if (empty($pickcenter)) {
            return false;
        }

        // Use first provider's data
        $pick = $pickcenter[0];
        $spread = abs($pick['spread'] ?? 0);

        if ($spread == 0) {
            // Pick-em
            $game->setSpread(0.0);
            $game->setSpreadTeam($game->getTeam1());
            return true;
        }

        // Determine which team is favored from homeTeamOdds/awayTeamOdds
        $homeOdds = $pick['homeTeamOdds'] ?? [];
        $awayOdds = $pick['awayTeamOdds'] ?? [];

        // Get the team names from the header
        $header = $summary['header'] ?? [];
        $competitors = $header['competitions'][0]['competitors'] ?? [];

        $homeTeamName = null;
        $awayTeamName = null;
        foreach ($competitors as $comp) {
            if (($comp['homeAway'] ?? '') === 'home') {
                $homeTeamName = strtolower($comp['team']['displayName'] ?? '');
            } else {
                $awayTeamName = strtolower($comp['team']['displayName'] ?? '');
            }
        }

        $team1Name = strtolower($game->getTeam1()->getName());
        $team2Name = strtolower($game->getTeam2()->getName());

        $homeFavored = $homeOdds['favorite'] ?? false;
        $favoredName = $homeFavored ? $homeTeamName : $awayTeamName;

        $game->setSpread($spread);

        if ($favoredName === $team1Name) {
            $game->setSpreadTeam($game->getTeam1());
        } elseif ($favoredName === $team2Name) {
            $game->setSpreadTeam($game->getTeam2());
        } else {
            // Fallback: negative spread means home team is favored
            $game->setSpreadTeam($game->getTeam1());
        }

        return true;
    }

    private function applyScores(Game $game, array $competition): bool
    {
        $team1Name = strtolower($game->getTeam1()->getName());
        $team2Name = strtolower($game->getTeam2()->getName());

        foreach ($competition['competitors'] ?? [] as $competitor) {
            $teamData = $competitor['team'] ?? [];
            $name = strtolower($teamData['displayName'] ?? '');
            $score = $competitor['score'] ?? null;

            if ($score === null) {
                continue;
            }

            if ($name === $team1Name) {
                $game->setTeam1Score((int) $score);
            } elseif ($name === $team2Name) {
                $game->setTeam2Score((int) $score);
            }
        }

        if ($game->getTeam1Score() !== null && $game->getTeam2Score() !== null) {
            $game->setIsComplete(true);
            if ($game->getTeam1Score() > $game->getTeam2Score()) {
                $game->setWinner($game->getTeam1());
            } else {
                $game->setWinner($game->getTeam2());
            }
            return true;
        }

        return false;
    }

    private function parseRegion(array $competition): ?string
    {
        foreach ($competition['notes'] ?? [] as $note) {
            $headline = $note['headline'] ?? '';
            if (preg_match('/- (East|West|South|Midwest) Region -/', $headline, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
}
