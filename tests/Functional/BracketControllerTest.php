<?php

namespace App\Tests\Functional;

class BracketControllerTest extends WebTestCase
{
    public function testBracketIndexPage(): void
    {
        $this->createUser('bc_index');
        $this->loginViaForm('bc_index');
        $this->client->request('GET', '/brackets');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Your Brackets');
    }

    public function testBracketShowPage(): void
    {
        $user1 = $this->createUser('bc_show_p1');
        $user2 = $this->createUser('bc_show_p2');
        $bracket = $this->createBracket($user1, $user2);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $this->createGame($bracket, $duke, $norfolk);

        $this->loginViaForm('bc_show_p1');
        $this->client->request('GET', "/brackets/{$bracket->getId()}");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Test Bracket');
    }

    public function testBracketShowDisplaysScoreCard(): void
    {
        $user1 = $this->createUser('bc_score_p1');
        $user2 = $this->createUser('bc_score_p2');
        $bracket = $this->createBracket($user1, $user2);

        $duke = $this->createTeam('Duke', 1);
        $norfolk = $this->createTeam('Norfolk St', 16);
        $this->createGame($bracket, $duke, $norfolk);

        $this->loginViaForm('bc_score_p1');
        $this->client->request('GET', "/brackets/{$bracket->getId()}");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#score-card');
    }

    public function testCreateBracketPage(): void
    {
        $this->createUser('bc_create');
        $this->loginViaForm('bc_create');
        $this->client->request('GET', '/brackets/create');
        $this->assertResponseIsSuccessful();
    }

    public function testEditBracketPage(): void
    {
        $user1 = $this->createUser('bc_edit_p1');
        $user2 = $this->createUser('bc_edit_p2');
        $bracket = $this->createBracket($user1, $user2);

        $this->loginViaForm('bc_edit_p1');
        $this->client->request('GET', "/brackets/{$bracket->getId()}/edit");
        $this->assertResponseIsSuccessful();
    }
}
