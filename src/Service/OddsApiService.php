<?php

namespace App\Service;

use App\Entity\Bracket;
use App\Entity\Game;
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

    /**
     * Pull spreads from The Odds API and match to games in the bracket.
     * @return array{matched: int, total: int}
     */
    public function pullSpreads(Bracket $bracket, int $roundNumber): array
    {
        if (empty($this->oddsApiKey)) {
            return ['matched' => 0, 'total' => 0, 'error' => 'No API key configured'];
        }

        $response = $this->httpClient->request('GET', self::BASE_URL . '/odds', [
            'query' => [
                'apiKey' => $this->oddsApiKey,
                'regions' => 'us',
                'markets' => 'spreads',
                'oddsFormat' => 'american',
            ],
        ]);

        $data = $response->toArray();
        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);

        $matched = 0;
        foreach ($data as $apiGame) {
            $homeTeam = $this->normalizeTeamName($apiGame['home_team'] ?? '');
            $awayTeam = $this->normalizeTeamName($apiGame['away_team'] ?? '');

            foreach ($games as $game) {
                if ($game->getSpread() !== null) {
                    continue; // Already has a spread
                }

                $team1Name = $game->getTeam1() ? $this->normalizeTeamName($game->getTeam1()->getName()) : '';
                $team2Name = $game->getTeam2() ? $this->normalizeTeamName($game->getTeam2()->getName()) : '';

                if (empty($team1Name) || empty($team2Name)) {
                    continue;
                }

                // Match by team name (fuzzy)
                $matchesTeam1 = $this->teamsMatch($team1Name, $homeTeam) || $this->teamsMatch($team1Name, $awayTeam);
                $matchesTeam2 = $this->teamsMatch($team2Name, $homeTeam) || $this->teamsMatch($team2Name, $awayTeam);

                if ($matchesTeam1 && $matchesTeam2) {
                    $this->applySpread($game, $apiGame);
                    $game->setExternalGameId($apiGame['id'] ?? null);
                    $matched++;
                    break;
                }
            }
        }

        $this->em->flush();

        return ['matched' => $matched, 'total' => count($data)];
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

        $response = $this->httpClient->request('GET', self::BASE_URL . '/scores', [
            'query' => [
                'apiKey' => $this->oddsApiKey,
                'daysFrom' => 3,
            ],
        ]);

        $data = $response->toArray();
        $games = $this->gameRepository->findByBracketAndRound($bracket, $roundNumber);

        $updated = 0;
        foreach ($data as $apiGame) {
            if (!($apiGame['completed'] ?? false)) {
                continue;
            }

            $homeTeam = $this->normalizeTeamName($apiGame['home_team'] ?? '');
            $awayTeam = $this->normalizeTeamName($apiGame['away_team'] ?? '');

            foreach ($games as $game) {
                if ($game->isComplete()) {
                    continue;
                }

                $team1Name = $game->getTeam1() ? $this->normalizeTeamName($game->getTeam1()->getName()) : '';
                $team2Name = $game->getTeam2() ? $this->normalizeTeamName($game->getTeam2()->getName()) : '';

                $matchesTeam1 = $this->teamsMatch($team1Name, $homeTeam) || $this->teamsMatch($team1Name, $awayTeam);
                $matchesTeam2 = $this->teamsMatch($team2Name, $homeTeam) || $this->teamsMatch($team2Name, $awayTeam);

                if ($matchesTeam1 && $matchesTeam2) {
                    $this->applyScores($game, $apiGame);
                    $updated++;
                    break;
                }
            }
        }

        $this->em->flush();

        return ['updated' => $updated];
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

            foreach ($market['outcomes'] as $outcome) {
                $outcomeName = $this->normalizeTeamName($outcome['name']);
                $team1Name = $game->getTeam1() ? $this->normalizeTeamName($game->getTeam1()->getName()) : '';
                $team2Name = $game->getTeam2() ? $this->normalizeTeamName($game->getTeam2()->getName()) : '';

                if ($this->teamsMatch($outcomeName, $team1Name) && $outcome['point'] < 0) {
                    $game->setSpread(abs($outcome['point']));
                    $game->setSpreadTeam($game->getTeam1());
                    return;
                }
                if ($this->teamsMatch($outcomeName, $team2Name) && $outcome['point'] < 0) {
                    $game->setSpread(abs($outcome['point']));
                    $game->setSpreadTeam($game->getTeam2());
                    return;
                }
            }

            // If no negative point found, use first outcome
            if (!empty($market['outcomes'])) {
                $outcome = $market['outcomes'][0];
                $game->setSpread(abs($outcome['point']));
                $outcomeName = $this->normalizeTeamName($outcome['name']);
                $team1Name = $game->getTeam1() ? $this->normalizeTeamName($game->getTeam1()->getName()) : '';

                if ($this->teamsMatch($outcomeName, $team1Name)) {
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

        $team1Name = $game->getTeam1() ? $this->normalizeTeamName($game->getTeam1()->getName()) : '';
        $team2Name = $game->getTeam2() ? $this->normalizeTeamName($game->getTeam2()->getName()) : '';

        foreach ($scores as $scoreData) {
            $scoreName = $this->normalizeTeamName($scoreData['name']);
            $score = (int) $scoreData['score'];

            if ($this->teamsMatch($scoreName, $team1Name)) {
                $game->setTeam1Score($score);
            } elseif ($this->teamsMatch($scoreName, $team2Name)) {
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

    private function normalizeTeamName(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $name)));
    }

    private function teamsMatch(string $name1, string $name2): bool
    {
        if (empty($name1) || empty($name2)) {
            return false;
        }
        // Exact match after normalization
        if ($name1 === $name2) {
            return true;
        }
        // One contains the other
        if (str_contains($name1, $name2) || str_contains($name2, $name1)) {
            return true;
        }
        return false;
    }
}
