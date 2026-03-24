<?php

namespace App\Tests\Functional;

use App\Entity\Pick;

class GameControllerTest extends WebTestCase
{
    public function testAssignPick(): void
    {
        $user1 = $this->createUser('gc_p1');
        $user2 = $this->createUser('gc_p2');
        $bracket = $this->createBracket($user1, $user2, 1);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $game = $this->createGame($bracket, $duke, $norfolk);

        // Login as player1 (firstPicker=1, round 1 odd, index 0 even -> player 1 picks)
        $this->loginViaForm('gc_p1');
        $this->client->request('POST', "/api/games/{$game->getId()}/pick", [
            'team_id' => $duke->getId(),
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('html', $data);
        $this->assertArrayHasKey('scores', $data);
        $this->assertArrayHasKey('pickProgress', $data);

        // Verify picks were created in DB
        $this->em->clear();
        $picks = $this->em->getRepository(Pick::class)->findBy(['game' => $game->getId()]);
        $this->assertCount(2, $picks);

        $playerPicks = [];
        foreach ($picks as $pick) {
            $playerPicks[$pick->getPlayer()] = $pick->getTeam()->getName();
        }
        $this->assertSame('Duke', $playerPicks[1]);
        $this->assertSame('Norfolk St', $playerPicks[2]);
    }

    public function testAssignPickEnforcesTurn(): void
    {
        $user1 = $this->createUser('gc_turn_p1');
        $user2 = $this->createUser('gc_turn_p2');
        $bracket = $this->createBracket($user1, $user2, 1);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $game = $this->createGame($bracket, $duke, $norfolk);

        // Login as player2 — but it's player1's turn (firstPicker=1, round 1, index 0)
        $this->loginViaForm('gc_turn_p2');
        $this->client->request('POST', "/api/games/{$game->getId()}/pick", [
            'team_id' => $duke->getId(),
        ]);

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('not your turn', $data['error']);
    }

    public function testAssignPickRejectsNonPlayer(): void
    {
        $user1 = $this->createUser('gc_nonp_p1');
        $user2 = $this->createUser('gc_nonp_p2');
        $outsider = $this->createUser('gc_nonp_outsider');
        $bracket = $this->createBracket($user1, $user2);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $game = $this->createGame($bracket, $duke, $norfolk);

        $this->loginViaForm('gc_nonp_outsider');
        $this->client->request('POST', "/api/games/{$game->getId()}/pick", [
            'team_id' => $duke->getId(),
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAssignPickRejectsInvalidTeam(): void
    {
        $user1 = $this->createUser('gc_invalid_p1');
        $user2 = $this->createUser('gc_invalid_p2');
        $bracket = $this->createBracket($user1, $user2, 1);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $michigan = $this->createTeam('Michigan', 5);
        $this->em->flush();
        $game = $this->createGame($bracket, $duke, $norfolk);

        $this->loginViaForm('gc_invalid_p1');
        $this->client->request('POST', "/api/games/{$game->getId()}/pick", [
            'team_id' => $michigan->getId(),
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAssignPickReEvaluatesCompletedGame(): void
    {
        $user1 = $this->createUser('gc_reeval_p1');
        $user2 = $this->createUser('gc_reeval_p2');
        $bracket = $this->createBracket($user1, $user2, 1);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $game = $this->createGame($bracket, $duke, $norfolk);

        // Mark game complete with scores
        $game->setTeam1Score(82);
        $game->setTeam2Score(65);
        $game->setIsComplete(true);
        $game->setWinner($duke);
        $this->em->flush();

        $this->loginViaForm('gc_reeval_p1');
        $this->client->request('POST', "/api/games/{$game->getId()}/pick", [
            'team_id' => $duke->getId(),
        ]);

        $this->assertResponseIsSuccessful();

        // Picks should be evaluated since game is complete
        $this->em->clear();
        $picks = $this->em->getRepository(Pick::class)->findBy(['game' => $game->getId()]);
        foreach ($picks as $pick) {
            $this->assertNotNull($pick->isWinner(), 'Picks should be evaluated on completed game');
        }
    }

    public function testSetSpread(): void
    {
        $user1 = $this->createUser('gc_spread_p1');
        $user2 = $this->createUser('gc_spread_p2');
        $bracket = $this->createBracket($user1, $user2);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $game = $this->createGame($bracket, $duke, $norfolk);

        $this->loginViaForm('gc_spread_p1');
        $this->client->request('POST', "/api/games/{$game->getId()}/spread", [
            'spread' => '5.5',
            'spread_team_id' => $duke->getId(),
        ]);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $game = $this->em->getRepository(\App\Entity\Game::class)->find($game->getId());
        $this->assertSame(5.5, $game->getSpread());
        $this->assertSame('Duke', $game->getSpreadTeam()->getName());
    }

    public function testSetSpreadReEvaluatesPicks(): void
    {
        $user1 = $this->createUser('gc_reeval_spread_p1');
        $user2 = $this->createUser('gc_reeval_spread_p2');
        $bracket = $this->createBracket($user1, $user2, 1);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $game = $this->createGame($bracket, $duke, $norfolk);

        // Add picks and complete game
        $pick1 = new Pick();
        $pick1->setPlayer(1);
        $pick1->setTeam($duke);
        $pick1->setIsWinner(true); // Previously evaluated
        $game->addPick($pick1);
        $this->em->persist($pick1);

        $pick2 = new Pick();
        $pick2->setPlayer(2);
        $pick2->setTeam($norfolk);
        $pick2->setIsWinner(false);
        $game->addPick($pick2);
        $this->em->persist($pick2);

        $game->setTeam1Score(82);
        $game->setTeam2Score(65);
        $game->setIsComplete(true);
        $game->setWinner($duke);
        $this->em->flush();

        // Now set a spread that changes the evaluation
        // Duke won by 17, set spread to 18.5 -> Duke no longer covers
        $this->loginViaForm('gc_reeval_spread_p1');
        $this->client->request('POST', "/api/games/{$game->getId()}/spread", [
            'spread' => '18.5',
            'spread_team_id' => $duke->getId(),
        ]);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $picks = $this->em->getRepository(Pick::class)->findBy(['game' => $game->getId()]);
        $playerResults = [];
        foreach ($picks as $pick) {
            $playerResults[$pick->getPlayer()] = $pick->isWinner();
        }
        // Duke picked player should lose (margin 17 < 18.5)
        $this->assertFalse($playerResults[1]);
        // Norfolk picked player should win (underdog covered)
        $this->assertTrue($playerResults[2]);
    }

    public function testUnauthenticatedApiRejects(): void
    {
        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->client->request('POST', '/api/games/1/pick', ['team_id' => 1]);
    }
}
