<?php

namespace App\Controller;

use App\Entity\Game;
use App\Entity\Pick;
use App\Repository\TeamRepository;
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
    ): Response {
        if (!$request->getSession()->get('authenticated')) {
            return $this->json(['error' => 'Unauthorized'], 403);
        }

        $player = (int) $request->request->get('player');
        $teamId = (int) $request->request->get('team_id');

        if ($player < 1 || $player > 2) {
            return $this->json(['error' => 'Invalid player'], 400);
        }

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

        $em->flush();

        $bracket = $game->getBracket();

        return $this->render('game/_card.html.twig', [
            'game' => $game,
            'player1_name' => $bracket->getPlayer1Name(),
            'player2_name' => $bracket->getPlayer2Name(),
        ]);
    }

    #[Route('/api/games/{id}/spread', name: 'api_game_spread', methods: ['POST'])]
    public function updateSpread(
        Request $request,
        Game $game,
        EntityManagerInterface $em,
        TeamRepository $teamRepository,
        ScoringService $scoringService,
    ): JsonResponse {
        if (!$request->getSession()->get('authenticated')) {
            return $this->json(['error' => 'Unauthorized'], 403);
        }

        $spread = $request->request->get('spread');
        $spreadTeamId = $request->request->get('spread_team_id');

        if ($spread !== null && $spread !== '') {
            $game->setSpread((float) $spread);

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

        return $this->json(['success' => true]);
    }

    #[Route('/api/games/{id}/score', name: 'api_game_score', methods: ['POST'])]
    public function updateScore(
        Request $request,
        Game $game,
        EntityManagerInterface $em,
        ScoringService $scoringService,
    ): JsonResponse {
        if (!$request->getSession()->get('authenticated')) {
            return $this->json(['error' => 'Unauthorized'], 403);
        }

        $team1Score = $request->request->get('team1_score');
        $team2Score = $request->request->get('team2_score');

        if ($team1Score !== null && $team1Score !== '') {
            $game->setTeam1Score((int) $team1Score);
        }
        if ($team2Score !== null && $team2Score !== '') {
            $game->setTeam2Score((int) $team2Score);
        }

        // If both scores are set, determine winner and mark complete
        if ($game->getTeam1Score() !== null && $game->getTeam2Score() !== null) {
            $game->setIsComplete(true);
            if ($game->getTeam1Score() > $game->getTeam2Score()) {
                $game->setWinner($game->getTeam1());
            } else {
                $game->setWinner($game->getTeam2());
            }

            $em->flush();

            // Evaluate picks and advance winner
            $scoringService->evaluatePicks($game);
            $scoringService->advanceWinner($game);
        } else {
            $em->flush();
        }

        return $this->json(['success' => true]);
    }
}
