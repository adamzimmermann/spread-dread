<?php

namespace App\Controller;

use App\Entity\Game;
use App\Entity\Pick;
use App\Entity\User;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Service\ScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GameController extends AbstractController
{
    #[Route('/api/games/{id}/pick', name: 'api_game_pick', methods: ['POST'])]
    public function assignPick(
        Request $request,
        Game $game,
        EntityManagerInterface $em,
        TeamRepository $teamRepository,
        ScoringService $scoringService,
        UserRepository $userRepository,
    ): Response {
        $user = $this->requireUser($request, $userRepository);
        $bracket = $game->getBracket();
        $player = $bracket->getPlayerNumber($user);

        if (!$player) {
            return $this->json(['error' => 'You are not a player in this bracket'], 403);
        }

        $teamId = (int) $request->request->get('team_id');

        $team = $teamRepository->find($teamId);
        if (!$team) {
            return $this->json(['error' => 'Team not found'], 404);
        }

        // Ensure team is part of this game
        if ($team !== $game->getTeam1() && $team !== $game->getTeam2()) {
            return $this->json(['error' => 'Team is not in this game'], 400);
        }

        // Find or create pick for this player
        $pick = $game->getPickForPlayer($player);
        if ($pick) {
            $pick->setTeam($team);
        } else {
            $pick = new Pick();
            $pick->setPlayer($player);
            $pick->setTeam($team);
            $game->addPick($pick);
            $em->persist($pick);
        }

        // Auto-assign the other player to the other team
        $otherPlayer = $player === 1 ? 2 : 1;
        $otherTeam = ($team === $game->getTeam1()) ? $game->getTeam2() : $game->getTeam1();
        if ($otherTeam) {
            $otherPick = $game->getPickForPlayer($otherPlayer);
            if ($otherPick) {
                $otherPick->setTeam($otherTeam);
            } else {
                $otherPick = new Pick();
                $otherPick->setPlayer($otherPlayer);
                $otherPick->setTeam($otherTeam);
                $game->addPick($otherPick);
                $em->persist($otherPick);
            }
        }

        // Reset pick evaluations when reassigning on a completed game
        foreach ($game->getPicks() as $p) {
            $p->setIsWinner(null);
        }

        $em->flush();

        // Re-evaluate picks if game is already complete
        if ($game->isComplete()) {
            $scoringService->evaluatePicks($game);
        }

        $scores = $scoringService->calculateScores($bracket);
        $currentPlayer = $player;

        $cardHtml = $this->renderView('game/_card.html.twig', [
            'game' => $game,
            'player1_name' => $bracket->getPlayer1Name(),
            'player2_name' => $bracket->getPlayer2Name(),
            'current_player' => $currentPlayer,
        ]);

        return $this->json([
            'html' => $cardHtml,
            'scores' => $scores,
        ]);
    }

    #[Route('/api/games/{id}/spread', name: 'api_game_spread', methods: ['POST'])]
    public function updateSpread(
        Request $request,
        Game $game,
        EntityManagerInterface $em,
        TeamRepository $teamRepository,
        ScoringService $scoringService,
        UserRepository $userRepository,
    ): JsonResponse {
        $user = $this->requireUser($request, $userRepository);
        $bracket = $game->getBracket();
        $currentPlayer = $bracket->getPlayerNumber($user);

        $spread = $request->request->get('spread');
        $spreadTeamId = $request->request->get('spread_team_id');

        if ($spread !== null && $spread !== '') {
            $game->setSpread(abs((float) $spread));

            if ($spreadTeamId) {
                $spreadTeam = $teamRepository->find((int) $spreadTeamId);
                $game->setSpreadTeam($spreadTeam);
            }
        } else {
            $game->setSpread(null);
            $game->setSpreadTeam(null);
        }

        $em->flush();

        // Re-evaluate picks if game is already complete
        if ($game->isComplete()) {
            // Reset pick results so they get re-evaluated with the new spread
            foreach ($game->getPicks() as $pick) {
                $pick->setIsWinner(null);
            }
            $em->flush();
            $scoringService->evaluatePicks($game);
        }

        $scores = $scoringService->calculateScores($bracket);

        return $this->json([
            'html' => $this->renderView('game/_card.html.twig', [
                'game' => $game,
                'player1_name' => $bracket->getPlayer1Name(),
                'player2_name' => $bracket->getPlayer2Name(),
                'current_player' => $currentPlayer,
            ]),
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
