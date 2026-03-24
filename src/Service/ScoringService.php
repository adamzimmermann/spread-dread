<?php

namespace App\Service;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;

class ScoringService
{
    public function __construct(
        private EntityManagerInterface $em,
        private GameRepository $gameRepository,
    ) {
    }

    /**
     * Evaluate picks for a completed game against the spread.
     */
    public function evaluatePicks(Game $game): void
    {
        if (!$game->isComplete() || $game->getWinner() === null) {
            return;
        }

        foreach ($game->getPicks() as $pick) {
            if ($pick->isWinner() !== null) {
                continue; // Already evaluated
            }

            $pickedTeam = $pick->getTeam();
            $spread = $game->getSpread();
            $spreadTeam = $game->getSpreadTeam();

            // If no spread set, just check if picked team won outright
            if ($spread === null || $spreadTeam === null) {
                $pick->setIsWinner($pickedTeam === $game->getWinner());
                continue;
            }

            // Calculate if picked team covered the spread
            $team1Score = $game->getTeam1Score();
            $team2Score = $game->getTeam2Score();

            if ($team1Score === null || $team2Score === null) {
                continue;
            }

            // Adjust score by spread: the spread team gets points added
            // e.g., Duke -5.5 means Duke is favored by 5.5
            // If you pick Duke, they need to win by more than 5.5
            // If you pick the underdog, they need to lose by less than 5.5 (or win)
            $pickedTeamScore = ($pickedTeam === $game->getTeam1()) ? $team1Score : $team2Score;
            $otherTeamScore = ($pickedTeam === $game->getTeam1()) ? $team2Score : $team1Score;

            if ($pickedTeam === $spreadTeam) {
                // Picked the favorite: they must win by more than the spread
                $adjustedMargin = $pickedTeamScore - $otherTeamScore - $spread;
            } else {
                // Picked the underdog: they get the spread added
                $adjustedMargin = $pickedTeamScore + $spread - $otherTeamScore;
            }

            if ($adjustedMargin > 0) {
                $pick->setIsWinner(true);
            } elseif ($adjustedMargin < 0) {
                $pick->setIsWinner(false);
            } else {
                // Push — exact tie against spread, no point awarded
                $pick->setIsWinner(false);
            }
        }

        $this->em->flush();
    }

    /**
     * Advance the winner to the next game in the bracket.
     */
    public function advanceWinner(Game $game): void
    {
        if (!$game->isComplete() || $game->getWinner() === null || $game->getNextGame() === null) {
            return;
        }

        $nextGame = $game->getNextGame();
        $winner = $game->getWinner();

        // Determine if this game feeds into team1 or team2 slot
        // Games with odd bracketPosition feed to team1, even to team2
        if ($game->getBracketPosition() % 2 === 1) {
            $nextGame->setTeam1($winner);
        } else {
            $nextGame->setTeam2($winner);
        }

        $this->em->flush();
    }

    /**
     * Calculate total scores for both players across a bracket.
     * @return array{player1: int, player2: int, player1_name: string, player2_name: string}
     */
    public function calculateScores(Bracket $bracket): array
    {
        $player1Score = 0;
        $player2Score = 0;

        $games = $this->gameRepository->findByBracket($bracket);
        foreach ($games as $game) {
            foreach ($game->getPicks() as $pick) {
                if ($pick->isWinner() === true) {
                    if ($pick->getPlayer() === 1) {
                        $player1Score++;
                    } else {
                        $player2Score++;
                    }
                }
            }
        }

        return [
            'player1' => $player1Score,
            'player2' => $player2Score,
            'player1_name' => $bracket->getPlayer1Name(),
            'player2_name' => $bracket->getPlayer2Name(),
        ];
    }
}
