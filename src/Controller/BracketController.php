<?php

namespace App\Controller;

use App\Entity\Bracket;
use App\Entity\Team;
use App\Repository\BracketRepository;
use App\Repository\GameRepository;
use App\Repository\TeamRepository;
use App\Service\BracketBuilderService;
use App\Service\OddsApiService;
use App\Service\ScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BracketController extends AbstractController
{
    #[Route('/brackets', name: 'app_bracket_index')]
    public function index(Request $request, BracketRepository $bracketRepository): Response
    {
        $this->requireAuth($request);
        $brackets = $bracketRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('bracket/index.html.twig', [
            'brackets' => $brackets,
        ]);
    }

    #[Route('/brackets/create', name: 'app_bracket_create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        BracketBuilderService $bracketBuilder,
    ): Response {
        $this->requireAuth($request);

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $year = (int) $request->request->get('year', date('Y'));

            if (empty($name)) {
                $this->addFlash('error', 'Bracket name is required.');
                return $this->render('bracket/create.html.twig');
            }

            $bracket = new Bracket();
            $bracket->setName($name);
            $bracket->setYear($year);

            $player1 = trim($request->request->get('player1_name', ''));
            $player2 = trim($request->request->get('player2_name', ''));
            if (!empty($player1)) {
                $bracket->setPlayer1Name($player1);
            }
            if (!empty($player2)) {
                $bracket->setPlayer2Name($player2);
            }

            $em->persist($bracket);

            $bracketBuilder->buildBracket($bracket);

            $this->addFlash('success', 'Bracket created! Now add teams.');
            return $this->redirectToRoute('app_bracket_teams', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/create.html.twig');
    }

    #[Route('/brackets/{id}/edit', name: 'app_bracket_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Bracket $bracket,
        EntityManagerInterface $em,
    ): Response {
        $this->requireAuth($request);

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $player1 = trim($request->request->get('player1_name', ''));
            $player2 = trim($request->request->get('player2_name', ''));

            if (!empty($name)) {
                $bracket->setName($name);
            }
            if (!empty($player1)) {
                $bracket->setPlayer1Name($player1);
            }
            if (!empty($player2)) {
                $bracket->setPlayer2Name($player2);
            }

            $em->flush();

            $this->addFlash('success', 'Bracket updated.');
            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/edit.html.twig', [
            'bracket' => $bracket,
        ]);
    }

    #[Route('/brackets/{id}/teams', name: 'app_bracket_teams', methods: ['GET', 'POST'])]
    public function teams(
        Request $request,
        Bracket $bracket,
        TeamRepository $teamRepository,
        EntityManagerInterface $em,
        GameRepository $gameRepository,
    ): Response {
        $this->requireAuth($request);

        $regions = ['East', 'West', 'South', 'Midwest'];
        $seedMatchups = [
            [1, 16], [8, 9], [5, 12], [4, 13],
            [6, 11], [3, 14], [7, 10], [2, 15],
        ];

        if ($request->isMethod('POST')) {
            // Process team entries per region
            foreach ($regions as $region) {
                for ($seed = 1; $seed <= 16; $seed++) {
                    $teamName = trim($request->request->get("team_{$region}_{$seed}", ''));
                    if (empty($teamName)) {
                        continue;
                    }

                    // Check if team already exists
                    $existing = $teamRepository->findOneBy([
                        'year' => $bracket->getYear(),
                        'region' => $region,
                        'seed' => $seed,
                    ]);

                    if ($existing) {
                        $existing->setName($teamName);
                    } else {
                        $team = new Team();
                        $team->setName($teamName);
                        $team->setSeed($seed);
                        $team->setRegion($region);
                        $team->setYear($bracket->getYear());
                        $em->persist($team);
                    }
                }
            }
            $em->flush();

            // Now assign teams to R64 games
            $r64Games = $gameRepository->findByBracketAndRound($bracket, 1);
            $teams = $teamRepository->findByYear($bracket->getYear());
            $teamsByRegion = [];
            foreach ($teams as $team) {
                $teamsByRegion[$team->getRegion()][$team->getSeed()] = $team;
            }

            foreach ($r64Games as $game) {
                $region = $game->getRegion();
                $pos = $game->getBracketPosition();
                $matchup = $seedMatchups[$pos - 1];

                if (isset($teamsByRegion[$region][$matchup[0]])) {
                    $game->setTeam1($teamsByRegion[$region][$matchup[0]]);
                }
                if (isset($teamsByRegion[$region][$matchup[1]])) {
                    $game->setTeam2($teamsByRegion[$region][$matchup[1]]);
                }
            }
            $em->flush();

            $this->addFlash('success', 'Teams saved!');
            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        // Load existing teams
        $existingTeams = [];
        foreach ($regions as $region) {
            $regionTeams = $teamRepository->findByYearAndRegion($bracket->getYear(), $region);
            foreach ($regionTeams as $team) {
                $existingTeams[$region][$team->getSeed()] = $team->getName();
            }
        }

        return $this->render('bracket/teams.html.twig', [
            'bracket' => $bracket,
            'regions' => $regions,
            'existingTeams' => $existingTeams,
        ]);
    }

    #[Route('/brackets/{id}', name: 'app_bracket_show')]
    public function show(
        Request $request,
        Bracket $bracket,
        GameRepository $gameRepository,
        ScoringService $scoringService,
    ): Response {
        $this->requireAuth($request);

        $round = (int) $request->query->get('round', 1);
        $games = $gameRepository->findByBracketAndRound($bracket, $round);
        $scores = $scoringService->calculateScores($bracket);

        // Determine which rounds have games
        $availableRounds = [];
        for ($r = 1; $r <= 6; $r++) {
            $roundGames = $gameRepository->findByBracketAndRound($bracket, $r);
            if (!empty($roundGames)) {
                $availableRounds[$r] = \App\Entity\Round::getRoundName($r);
            }
        }

        return $this->render('bracket/show.html.twig', [
            'bracket' => $bracket,
            'games' => $games,
            'currentRound' => $round,
            'availableRounds' => $availableRounds,
            'scores' => $scores,
        ]);
    }

    #[Route('/api/brackets/{id}/pull-spreads', name: 'api_bracket_pull_spreads', methods: ['POST'])]
    public function pullSpreads(
        Request $request,
        Bracket $bracket,
        OddsApiService $oddsApiService,
    ): JsonResponse {
        $this->requireAuth($request);

        $round = (int) $request->request->get('round', 1);
        $result = $oddsApiService->pullSpreads($bracket, $round);

        return $this->json($result);
    }

    #[Route('/api/brackets/{id}/update-scores', name: 'api_bracket_update_scores', methods: ['POST'])]
    public function updateScores(
        Request $request,
        Bracket $bracket,
        OddsApiService $oddsApiService,
        ScoringService $scoringService,
        GameRepository $gameRepository,
    ): JsonResponse {
        $this->requireAuth($request);

        $round = (int) $request->request->get('round', 1);
        $result = $oddsApiService->updateScores($bracket, $round);

        // Evaluate picks and advance winners for completed games
        $games = $gameRepository->findByBracketAndRound($bracket, $round);
        foreach ($games as $game) {
            if ($game->isComplete()) {
                $scoringService->evaluatePicks($game);
                $scoringService->advanceWinner($game);
            }
        }

        $scores = $scoringService->calculateScores($bracket);

        return $this->json([
            'result' => $result,
            'scores' => $scores,
        ]);
    }

    private function requireAuth(Request $request): void
    {
        if (!$request->getSession()->get('authenticated')) {
            throw $this->createAccessDeniedException();
        }
    }
}
