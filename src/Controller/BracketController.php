<?php

namespace App\Controller;

use App\Entity\Bracket;
use App\Entity\User;
use App\Repository\BracketRepository;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use App\Service\BracketBuilderService;
use App\Service\EspnApiService;
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
    public function index(Request $request, BracketRepository $bracketRepository, UserRepository $userRepository): Response
    {
        $user = $this->requireUser($request, $userRepository);
        $brackets = $bracketRepository->findByUser($user);

        return $this->render('bracket/index.html.twig', [
            'brackets' => $brackets,
        ]);
    }

    #[Route('/brackets/create', name: 'app_bracket_create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        BracketBuilderService $bracketBuilder,
        EspnApiService $espnApiService,
        UserRepository $userRepository,
    ): Response {
        $user = $this->requireUser($request, $userRepository);

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));
            $year = (int) $request->request->get('year', date('Y'));

            if (empty($name)) {
                $this->addFlash('error', 'Bracket name is required.');
                return $this->render('bracket/create.html.twig', [
                    'users' => $userRepository->findAll(),
                ]);
            }

            $bracket = new Bracket();
            $bracket->setName($name);
            $bracket->setYear($year);

            $player1Id = $request->request->get('player1_id');
            $player2Id = $request->request->get('player2_id');
            if ($player1Id) {
                $bracket->setPlayer1($userRepository->find((int) $player1Id));
            }
            if ($player2Id) {
                $bracket->setPlayer2($userRepository->find((int) $player2Id));
            }

            $em->persist($bracket);

            $bracketBuilder->buildBracket($bracket);

            // Auto-populate teams from ESPN
            $teamResult = $espnApiService->populateBracketTeams($bracket);
            if ($teamResult['success']) {
                $this->addFlash('success', 'Bracket created with ' . $teamResult['count'] . ' teams loaded from ESPN!');
            } else {
                $this->addFlash('warning', 'Bracket created but teams could not be loaded: ' . ($teamResult['error'] ?? 'Unknown error'));
            }

            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/create.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/brackets/{id}/edit', name: 'app_bracket_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Bracket $bracket,
        EntityManagerInterface $em,
        UserRepository $userRepository,
    ): Response {
        $user = $this->requireUser($request, $userRepository);

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('name', ''));

            if (!empty($name)) {
                $bracket->setName($name);
            }

            $player1Id = $request->request->get('player1_id');
            $player2Id = $request->request->get('player2_id');
            $bracket->setPlayer1($player1Id ? $userRepository->find((int) $player1Id) : null);
            $bracket->setPlayer2($player2Id ? $userRepository->find((int) $player2Id) : null);

            $em->flush();

            $this->addFlash('success', 'Bracket updated.');
            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/edit.html.twig', [
            'bracket' => $bracket,
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/brackets/{id}', name: 'app_bracket_show')]
    public function show(
        Request $request,
        Bracket $bracket,
        GameRepository $gameRepository,
        ScoringService $scoringService,
        UserRepository $userRepository,
    ): Response {
        $user = $this->requireUser($request, $userRepository);
        $currentPlayer = $bracket->getPlayerNumber($user);

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

        $hasSpreads = false;
        $hasPicks = false;
        foreach ($games as $game) {
            if ($game->getSpread() !== null) {
                $hasSpreads = true;
            }
            if (!$game->getPicks()->isEmpty()) {
                $hasPicks = true;
            }
            if ($hasSpreads && $hasPicks) {
                break;
            }
        }

        return $this->render('bracket/show.html.twig', [
            'bracket' => $bracket,
            'games' => $games,
            'currentRound' => $round,
            'availableRounds' => $availableRounds,
            'scores' => $scores,
            'current_player' => $currentPlayer,
            'roundHasSpreads' => $hasSpreads,
            'roundHasPicks' => $hasPicks,
        ]);
    }

    #[Route('/api/brackets/{id}/pull-spreads', name: 'api_bracket_pull_spreads', methods: ['POST'])]
    public function pullSpreads(
        Request $request,
        Bracket $bracket,
        EspnApiService $espnApiService,
        GameRepository $gameRepository,
        ScoringService $scoringService,
        UserRepository $userRepository,
    ): JsonResponse {
        $user = $this->requireUser($request, $userRepository);
        $currentPlayer = $bracket->getPlayerNumber($user);

        $round = (int) $request->request->get('round', 1);
        $result = $espnApiService->pullSpreads($bracket, $round);

        $games = $gameRepository->findByBracketAndRound($bracket, $round);
        $unmatchedIds = $result['unmatched'] ?? [];
        $cards = [];
        foreach ($games as $game) {
            $cards[$game->getId()] = $this->renderView('game/_card.html.twig', [
                'game' => $game,
                'player1_name' => $bracket->getPlayer1Name(),
                'player2_name' => $bracket->getPlayer2Name(),
                'current_player' => $currentPlayer,
                'warning' => in_array($game->getId(), $unmatchedIds) ? 'No API match found — spread must be set manually' : null,
            ]);
        }

        $scores = $scoringService->calculateScores($bracket);

        return $this->json([
            'result' => $result,
            'cards' => $cards,
            'scores' => $scores,
        ]);
    }

    #[Route('/api/brackets/{id}/update-scores', name: 'api_bracket_update_scores', methods: ['POST'])]
    public function updateScores(
        Request $request,
        Bracket $bracket,
        EspnApiService $espnApiService,
        ScoringService $scoringService,
        GameRepository $gameRepository,
        UserRepository $userRepository,
    ): JsonResponse {
        $user = $this->requireUser($request, $userRepository);
        $currentPlayer = $bracket->getPlayerNumber($user);

        $round = (int) $request->request->get('round', 1);
        $result = $espnApiService->updateScores($bracket, $round);

        // Evaluate picks and advance winners for completed games
        $games = $gameRepository->findByBracketAndRound($bracket, $round);
        foreach ($games as $game) {
            if ($game->isComplete()) {
                $scoringService->evaluatePicks($game);
                $scoringService->advanceWinner($game);
            }
        }

        $unmatchedIds = $result['unmatched'] ?? [];
        $cards = [];
        foreach ($games as $game) {
            $cards[$game->getId()] = $this->renderView('game/_card.html.twig', [
                'game' => $game,
                'player1_name' => $bracket->getPlayer1Name(),
                'player2_name' => $bracket->getPlayer2Name(),
                'current_player' => $currentPlayer,
                'warning' => in_array($game->getId(), $unmatchedIds) ? 'No API match found — scores must be entered manually' : null,
            ]);
        }

        $scores = $scoringService->calculateScores($bracket);

        return $this->json([
            'result' => $result,
            'cards' => $cards,
            'scores' => $scores,
        ]);
    }

    private function requireUser(Request $request, UserRepository $userRepository): User
    {
        $userId = $request->getSession()->get('user_id');
        $user = $userId ? $userRepository->find($userId) : null;
        if (!$user) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}
