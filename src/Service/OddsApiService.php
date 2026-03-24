<?php

namespace App\Service;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Team;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OddsApiService
{
    private const BASE_URL = 'https://api.the-odds-api.com/v4/sports/basketball_ncaab';
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private GameRepository $gameRepository,
        private string $oddsApiKey,
    ) {
    }

    private function fetchOddsData(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'apiKey' => $this->oddsApiKey,
                    'regions' => 'us',
                    'markets' => 'spreads',
                    'oddsFormat' => 'american',
                ],
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function fetchScoresData(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'apiKey' => $this->oddsApiKey,
                    'daysFrom' => 3,
                ],
            ]);
            return $response->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Pull spreads from The Odds API and match to games in the bracket.
     * @return array{matched: int, total: int, unmatched: array}
     */
    public function pullSpreads(Bracket $bracket, int $roundNumber): array
    {
        if (empty($this->oddsApiKey)) {
            return ['matched' => 0, 'total' => 0, 'error' => 'No API key configured'];
        }

        $data = $this->fetchOddsData(self::BASE_URL . '/odds');

        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);

        $matched = 0;
        $unmatched = [];
        foreach ($games as $game) {
            if ($game->getSpread() !== null) {
                continue; // Already has a spread
            }
            if (!$game->getTeam1() || !$game->getTeam2()) {
                continue; // Teams not yet assigned
            }

            $apiGame = $this->findApiMatch($game, $data);
            if ($apiGame) {
                $this->applySpread($game, $apiGame);
                $this->storeMatchData($game, $apiGame);
                $matched++;
            } else {
                $unmatched[] = $game->getId();
            }
        }

        $this->em->flush();

        return ['matched' => $matched, 'total' => count($games), 'unmatched' => $unmatched];
    }

    /**
     * Pull scores from The Odds API and update games.
     * @return array{updated: int}
     */
    public function updateScores(Bracket $bracket, int $roundNumber): array
    {
        if (empty($this->oddsApiKey)) {
            return ['updated' => 0, 'error' => 'No API key configured'];
        }

        $data = $this->fetchScoresData(self::BASE_URL . '/scores');
        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);

        $updated = 0;
        $unmatched = [];
        foreach ($games as $game) {
            if ($game->isComplete()) {
                continue;
            }
            if (!$game->getTeam1() || !$game->getTeam2()) {
                continue;
            }

            $apiGame = $this->findApiMatch($game, $data);
            if ($apiGame && ($apiGame['completed'] ?? false)) {
                $this->applyScores($game, $apiGame);
                $this->storeMatchData($game, $apiGame);
                $updated++;
            } elseif (!$apiGame) {
                $unmatched[] = $game->getId();
            }
        }

        $this->em->flush();

        return ['updated' => $updated, 'unmatched' => $unmatched];
    }

    /**
     * Find the matching API event for a game.
     * Priority: externalGameId > apiName on teams > fuzzy name match
     */
    private function findApiMatch(Game $game, array $apiEvents): ?array
    {
        // 1. Match by stored external game ID (most reliable)
        if ($game->getExternalGameId()) {
            foreach ($apiEvents as $event) {
                if (($event['id'] ?? '') === $game->getExternalGameId()) {
                    return $event;
                }
            }
        }

        $team1 = $game->getTeam1();
        $team2 = $game->getTeam2();
        if (!$team1 || !$team2) {
            return null;
        }

        // 2. Match by stored API names on teams (reliable after first match)
        if ($team1->getApiName() && $team2->getApiName()) {
            foreach ($apiEvents as $event) {
                $homeTeam = $event['home_team'] ?? '';
                $awayTeam = $event['away_team'] ?? '';
                $eventTeams = [$homeTeam, $awayTeam];

                if (in_array($team1->getApiName(), $eventTeams) && in_array($team2->getApiName(), $eventTeams)) {
                    return $event;
                }
            }
        }

        // 3. Fuzzy name match (fallback for first-time matching)
        $team1Normalized = $this->normalizeTeamName($team1->getName());
        $team2Normalized = $this->normalizeTeamName($team2->getName());
        $team1Api = $team1->getApiName() ? $this->normalizeTeamName($team1->getApiName()) : null;
        $team2Api = $team2->getApiName() ? $this->normalizeTeamName($team2->getApiName()) : null;

        foreach ($apiEvents as $event) {
            $homeNormalized = $this->normalizeTeamName($event['home_team'] ?? '');
            $awayNormalized = $this->normalizeTeamName($event['away_team'] ?? '');

            $match1 = $this->teamsMatch($team1Normalized, $homeNormalized)
                || $this->teamsMatch($team1Normalized, $awayNormalized)
                || ($team1Api && ($this->teamsMatch($team1Api, $homeNormalized) || $this->teamsMatch($team1Api, $awayNormalized)));

            $match2 = $this->teamsMatch($team2Normalized, $homeNormalized)
                || $this->teamsMatch($team2Normalized, $awayNormalized)
                || ($team2Api && ($this->teamsMatch($team2Api, $homeNormalized) || $this->teamsMatch($team2Api, $awayNormalized)));

            if ($match1 && $match2) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Store the API event ID and team API names for reliable future matching.
     */
    private function storeMatchData(Game $game, array $apiGame): void
    {
        $game->setExternalGameId($apiGame['id'] ?? null);

        $homeTeam = $apiGame['home_team'] ?? '';
        $awayTeam = $apiGame['away_team'] ?? '';

        $team1 = $game->getTeam1();
        $team2 = $game->getTeam2();
        if (!$team1 || !$team2) {
            return;
        }

        // Figure out which API team corresponds to which local team
        $team1Normalized = $this->normalizeTeamName($team1->getName());
        $homeNormalized = $this->normalizeTeamName($homeTeam);
        $awayNormalized = $this->normalizeTeamName($awayTeam);

        if ($this->teamsMatch($team1Normalized, $homeNormalized)
            || ($team1->getApiName() && $team1->getApiName() === $homeTeam)) {
            $team1->setApiName($homeTeam);
            $team2->setApiName($awayTeam);
        } else {
            $team1->setApiName($awayTeam);
            $team2->setApiName($homeTeam);
        }
    }

    private function applySpread(Game $game, array $apiGame): void
    {
        $bookmakers = $apiGame['bookmakers'] ?? [];
        if (empty($bookmakers)) {
            return;
        }

        // Use first bookmaker's spread
        $markets = $bookmakers[0]['markets'] ?? [];
        foreach ($markets as $market) {
            if ($market['key'] !== 'spreads') {
                continue;
            }

            // Find the favored team (negative point value)
            foreach ($market['outcomes'] as $outcome) {
                if ($outcome['point'] < 0) {
                    $favoredApiName = $outcome['name'];
                    $spread = abs($outcome['point']);

                    // Match favored team to local team
                    $team1 = $game->getTeam1();
                    $team2 = $game->getTeam2();

                    if ($this->apiNameMatchesTeam($favoredApiName, $team1)) {
                        $game->setSpread($spread);
                        $game->setSpreadTeam($team1);
                    } elseif ($this->apiNameMatchesTeam($favoredApiName, $team2)) {
                        $game->setSpread($spread);
                        $game->setSpreadTeam($team2);
                    }
                    return;
                }
            }

            // Pick-em (both 0 or equal): use first outcome
            if (!empty($market['outcomes'])) {
                $outcome = $market['outcomes'][0];
                $game->setSpread(abs($outcome['point']));

                if ($this->apiNameMatchesTeam($outcome['name'], $game->getTeam1())) {
                    $game->setSpreadTeam($game->getTeam1());
                } else {
                    $game->setSpreadTeam($game->getTeam2());
                }
            }
        }
    }

    private function applyScores(Game $game, array $apiGame): void
    {
        $scores = $apiGame['scores'] ?? [];
        if (empty($scores)) {
            return;
        }

        foreach ($scores as $scoreData) {
            $apiName = $scoreData['name'];
            $score = (int) $scoreData['score'];

            if ($this->apiNameMatchesTeam($apiName, $game->getTeam1())) {
                $game->setTeam1Score($score);
            } elseif ($this->apiNameMatchesTeam($apiName, $game->getTeam2())) {
                $game->setTeam2Score($score);
            }
        }

        // Determine winner
        if ($game->getTeam1Score() !== null && $game->getTeam2Score() !== null) {
            $game->setIsComplete(true);
            if ($game->getTeam1Score() > $game->getTeam2Score()) {
                $game->setWinner($game->getTeam1());
            } else {
                $game->setWinner($game->getTeam2());
            }
        }
    }

    /**
     * Check if an API team name matches a local team, using stored apiName first.
     */
    private function apiNameMatchesTeam(string $apiName, ?Team $team): bool
    {
        if (!$team) {
            return false;
        }

        // Exact match on stored API name (most reliable)
        if ($team->getApiName() !== null && $team->getApiName() === $apiName) {
            return true;
        }

        // Fuzzy fallback
        return $this->teamsMatch(
            $this->normalizeTeamName($apiName),
            $this->normalizeTeamName($team->getName())
        );
    }

    private function normalizeTeamName(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $name)));
    }

    private function teamsMatch(string $name1, string $name2): bool
    {
        if (empty($name1) || empty($name2)) {
            return false;
        }
        if ($name1 === $name2) {
            return true;
        }
        // One contains the other (handles "Duke" matching "Duke Blue Devils")
        if (str_contains($name1, $name2) || str_contains($name2, $name1)) {
            return true;
        }
        return false;
    }
}
