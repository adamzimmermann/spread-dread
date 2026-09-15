<?php

namespace App\Controller;

use App\Entity\Bracket;
use App\Repository\BracketRepository;
use App\Repository\GameRepository;
use App\Repository\UserRepository;
use App\Security\SessionAuthenticator;
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
    public function index(BracketRepository $bracketRepository, SessionAuthenticator $auth): Response
    {
        $user = $auth->requireUser();
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
        SessionAuthenticator $auth,
    ): Response {
        $user = $auth->requireUser();
        $opponents = $userRepository->findActiveOpponents($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $name = trim($request->request->get('name', ''));
            $year = (int) $request->request->get('year', date('Y'));
            $opponentUsername = trim($request->request->get('opponent_username', ''));

            $opponent = $opponentUsername === '' ? null : $userRepository->findByUsername($opponentUsername);
            $opponentIsValid = $opponent
                && $opponent->isActive()
                && $opponent->getId() !== $user->getId();

            $error = null;
            if ($name === '') {
                $error = 'Bracket name is required.';
            } elseif (!$opponentIsValid) {
                $error = 'Pick an opponent from the list.';
            }

            if ($error !== null) {
                $this->addFlash('error', $error);
                return $this->render('bracket/create.html.twig', [
                    'opponents' => $opponents,
                    'currentUser' => $user,
                ]);
            }

            $bracket = new Bracket();
            $bracket->setName($name);
            $bracket->setYear($year);
            $bracket->setFirstPicker(random_int(1, 2));
            $bracket->setPlayer1($user);
            $bracket->setPlayer2($opponent);

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
            'opponents' => $opponents,
            'currentUser' => $user,
        ]);
    }

    #[Route('/brackets/{id}/edit', name: 'app_bracket_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Bracket $bracket,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        SessionAuthenticator $auth,
    ): Response {
        $user = $auth->requireBracketAccess($bracket);
        $opponents = $userRepository->findActiveOpponents($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $name = trim($request->request->get('name', ''));
            if ($name !== '') {
                $bracket->setName($name);
            }

            $opponentUsername = trim($request->request->get('opponent_username', ''));
            if ($opponentUsername !== '') {
                $opponent = $userRepository->findByUsername($opponentUsername);

                // A participant re-submitting their own name (e.g. player 2
                // saving the form without touching the pre-filled opponent
                // field) is a no-op, not an attempt to insert themselves.
                // Only an outsider (typically an admin editing someone
                // else's bracket) is barred from naming themselves opponent.
                $actingUserIsParticipant = $bracket->hasPlayer($user);
                $opponentIsValid = $opponent
                    && $opponent->isActive()
                    && $opponent->getId() !== $bracket->getPlayer1()?->getId()
                    && ($actingUserIsParticipant || $opponent->getId() !== $user->getId());

                if ($opponentIsValid) {
                    $bracket->setPlayer2($opponent);
                } else {
                    $this->addFlash('error', 'Pick an opponent from the list.');
                    return $this->render('bracket/edit.html.twig', [
                        'bracket' => $bracket,
                        'opponents' => $opponents,
                    ]);
                }
            }

            $em->flush();
            $this->addFlash('success', 'Bracket updated.');
            return $this->redirectToRoute('app_bracket_show', ['id' => $bracket->getId()]);
        }

        return $this->render('bracket/edit.html.twig', [
            'bracket' => $bracket,
            'opponents' => $opponents,
        ]);
    }

    #[Route('/brackets/{id}', name: 'app_bracket_show')]
    public function show(
        Request $request,
        Bracket $bracket,
        GameRepository $gameRepository,
        ScoringService $scoringService,
        SessionAuthenticator $auth,
    ): Response {
        $user = $auth->requireBracketAccess($bracket);
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
        $pickerMap = [];
        $myPicksDone = 0;
        $myPickTotal = 0;
        $opponentPicksDone = 0;
        $opponentPickTotal = 0;
        $opponentPlayer = $currentPlayer === 1 ? 2 : ($currentPlayer === 2 ? 1 : null);

        foreach ($games as $index => $game) {
            if ($game->getSpread() !== null) {
                $hasSpreads = true;
            }
            if (!$game->getPicks()->isEmpty()) {
                $hasPicks = true;
            }

            // Compute picker for each game
            if ($game->getTeam1() && $game->getTeam2()) {
                $picker = $bracket->getPickerForGame($game, $index);
                $pickerMap[$game->getId()] = $picker;

                if ($currentPlayer !== null) {
                    if ($picker === $currentPlayer) {
                        $myPickTotal++;
                        if ($game->getPickForPlayer($currentPlayer)) {
                            $myPicksDone++;
                        }
                    } else {
                        $opponentPickTotal++;
                        if ($game->getPickForPlayer($opponentPlayer)) {
                            $opponentPicksDone++;
                        }
                    }
                }
            }
        }

        $opponentName = $currentPlayer === 1 ? $bracket->getPlayer2Name() : $bracket->getPlayer1Name();

        return $this->render('bracket/show.html.twig', [
            'bracket' => $bracket,
            'games' => $games,
            'currentRound' => $round,
            'availableRounds' => $availableRounds,
            'scores' => $scores,
            'current_player' => $currentPlayer,
            'roundHasSpreads' => $hasSpreads,
            'roundHasPicks' => $hasPicks,
            'pickerMap' => $pickerMap,
            'myPicksDone' => $myPicksDone,
            'myPickTotal' => $myPickTotal,
            'opponentPicksDone' => $opponentPicksDone,
            'opponentPickTotal' => $opponentPickTotal,
            'opponentName' => $opponentName,
        ]);
    }

    #[Route('/api/brackets/{id}/pull-spreads', name: 'api_bracket_pull_spreads', methods: ['POST'])]
    public function pullSpreads(
        Request $request,
        Bracket $bracket,
        EspnApiService $espnApiService,
        GameRepository $gameRepository,
        ScoringService $scoringService,
        SessionAuthenticator $auth,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $auth->requireBracketAccess($bracket);
        $currentPlayer = $bracket->getPlayerNumber($user);

        $round = (int) $request->request->get('round', 1);
        $result = $espnApiService->pullSpreads($bracket, $round);

        $games = $gameRepository->findByBracketAndRound($bracket, $round);
        $unmatchedIds = $result['unmatched'] ?? [];
        $cards = [];
        foreach ($games as $index => $game) {
            $picker = ($bracket->getFirstPicker() !== null && $game->getTeam1() && $game->getTeam2())
                ? $bracket->getPickerForGame($game, $index) : null;
            $cards[$game->getId()] = $this->renderView('game/_card.html.twig', [
                'game' => $game,
                'player1_name' => $bracket->getPlayer1Name(),
                'player2_name' => $bracket->getPlayer2Name(),
                'current_player' => $currentPlayer,
                'picker' => $picker,
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
        SessionAuthenticator $auth,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $auth->requireBracketAccess($bracket);
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
        foreach ($games as $index => $game) {
            $picker = ($bracket->getFirstPicker() !== null && $game->getTeam1() && $game->getTeam2())
                ? $bracket->getPickerForGame($game, $index) : null;
            $cards[$game->getId()] = $this->renderView('game/_card.html.twig', [
                'game' => $game,
                'player1_name' => $bracket->getPlayer1Name(),
                'player2_name' => $bracket->getPlayer2Name(),
                'current_player' => $currentPlayer,
                'picker' => $picker,
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
}
