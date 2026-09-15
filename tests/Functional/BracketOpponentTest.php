<?php

namespace App\Tests\Functional;

use App\Entity\UserStatus;
use App\Repository\BracketRepository;

class BracketOpponentTest extends WebTestCase
{
    public function testCreateFormListsActiveUsersExceptYourself(): void
    {
        $this->createUser('opp_list_me');
        $this->createUser('opp_list_other');
        $this->createUser('opp_list_disabled', 'password', null, false, UserStatus::Disabled);
        $this->loginViaForm('opp_list_me');

        $this->client->request('GET', '/brackets/create');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('datalist#opponents');

        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('opp_list_other', $html);
        $this->assertStringNotContainsString('opp_list_disabled', $html);
    }

    public function testCreatingABracketMakesYouPlayerOne(): void
    {
        $me = $this->createUser('opp_create_me');
        $them = $this->createUser('opp_create_them');
        $this->loginViaForm('opp_create_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Opponent Test Bracket',
            'year' => 2026,
            'opponent_username' => 'opp_create_them',
        ]);
        $this->client->submit($form);

        $brackets = static::getContainer()->get(BracketRepository::class)
            ->findBy(['name' => 'Opponent Test Bracket']);
        $this->assertCount(1, $brackets);
        $this->assertSame($me->getId(), $brackets[0]->getPlayer1()->getId());
        $this->assertSame($them->getId(), $brackets[0]->getPlayer2()->getId());
    }

    public function testUnknownOpponentIsRejected(): void
    {
        $this->createUser('opp_unknown_me');
        $this->loginViaForm('opp_unknown_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Bad Opponent',
            'year' => 2026,
            'opponent_username' => 'nobody_by_that_name',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');
        $this->assertCount(0, static::getContainer()->get(BracketRepository::class)->findBy(['name' => 'Bad Opponent']));
    }

    public function testDisabledOpponentIsRejected(): void
    {
        $this->createUser('opp_dis_me');
        $this->createUser('opp_dis_them', 'password', null, false, UserStatus::Disabled);
        $this->loginViaForm('opp_dis_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Disabled Opponent',
            'year' => 2026,
            'opponent_username' => 'opp_dis_them',
        ]);
        $this->client->submit($form);

        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');
    }

    public function testYouCannotPlayYourself(): void
    {
        $this->createUser('opp_self_me');
        $this->loginViaForm('opp_self_me');

        $crawler = $this->client->request('GET', '/brackets/create');
        $form = $crawler->selectButton('Create Bracket')->form([
            'name' => 'Self Bracket',
            'year' => 2026,
            'opponent_username' => 'opp_self_me',
        ]);
        $this->client->submit($form);

        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');
    }
}
