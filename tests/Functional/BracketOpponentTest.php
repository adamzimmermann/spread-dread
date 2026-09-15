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

        $crawler = $this->client->request('GET', '/brackets/create');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('datalist#opponents');

        // Scope to the datalist itself: the page separately renders
        // "{{ currentUser.username }} (you)" elsewhere, so a page-wide
        // string search would pass even if the viewer leaked into the
        // opponent list.
        $datalistHtml = $crawler->filter('datalist#opponents')->html();
        $this->assertStringContainsString('opp_list_other', $datalistHtml);
        $this->assertStringNotContainsString('opp_list_me', $datalistHtml);
        $this->assertStringNotContainsString('opp_list_disabled', $datalistHtml);
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

    public function testEditingOpponentAsPlayerSucceeds(): void
    {
        $player1 = $this->createUser('opp_edit_p1');
        $oldOpponent = $this->createUser('opp_edit_p2_old');
        $newOpponent = $this->createUser('opp_edit_p2_new');
        $bracket = $this->createBracket($player1, $oldOpponent);
        $bracketId = $bracket->getId();
        $newOpponentId = $newOpponent->getId();

        $this->loginViaForm('opp_edit_p1');

        $crawler = $this->client->request('GET', "/brackets/{$bracketId}/edit");
        $form = $crawler->selectButton('Save Changes')->form([
            'opponent_username' => 'opp_edit_p2_new',
        ]);
        $this->client->submit($form);

        $refetched = static::getContainer()->get(BracketRepository::class)->find($bracketId);
        $this->assertSame($newOpponentId, $refetched->getPlayer2()->getId());
    }

    public function testAdminCannotSetSelfAsOpponentOnEdit(): void
    {
        $player1 = $this->createUser('opp_edit_adm_p1');
        $player2 = $this->createUser('opp_edit_adm_p2');
        $this->createUser('opp_edit_admin', 'password', null, true);
        $bracket = $this->createBracket($player1, $player2);
        $bracketId = $bracket->getId();
        $originalPlayer2Id = $player2->getId();

        $this->loginViaForm('opp_edit_admin');

        $crawler = $this->client->request('GET', "/brackets/{$bracketId}/edit");
        $form = $crawler->selectButton('Save Changes')->form([
            'opponent_username' => 'opp_edit_admin',
        ]);
        $this->client->submit($form);

        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');

        $refetched = static::getContainer()->get(BracketRepository::class)->find($bracketId);
        $this->assertSame($originalPlayer2Id, $refetched->getPlayer2()->getId());
    }

    public function testPlayerTwoEditingOwnBracketWithoutTouchingOpponentSucceeds(): void
    {
        $player1 = $this->createUser('opp_edit_p2self_p1');
        $player2 = $this->createUser('opp_edit_p2self_p2');
        $bracket = $this->createBracket($player1, $player2);
        $bracketId = $bracket->getId();
        $player2Id = $player2->getId();

        $this->loginViaForm('opp_edit_p2self_p2');

        // Deliberately do not pass opponent_username: the form's own
        // pre-filled default (the current player 2's own username, per
        // edit.html.twig) travels with the submission unmodified, exactly
        // as it would if a real player saved the form without touching
        // that field.
        $crawler = $this->client->request('GET', "/brackets/{$bracketId}/edit");
        $form = $crawler->selectButton('Save Changes')->form([
            'name' => 'Renamed By Player Two',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects("/brackets/{$bracketId}");

        $refetched = static::getContainer()->get(BracketRepository::class)->find($bracketId);
        $this->assertSame($player2Id, $refetched->getPlayer2()->getId());
        $this->assertSame('Renamed By Player Two', $refetched->getName());
    }

    public function testRejectedEditLeavesPlayerTwoUnchanged(): void
    {
        $player1 = $this->createUser('opp_edit_rej_p1');
        $player2 = $this->createUser('opp_edit_rej_p2');
        $this->createUser('opp_edit_rej_disabled', 'password', null, false, UserStatus::Disabled);
        $bracket = $this->createBracket($player1, $player2);
        $bracketId = $bracket->getId();
        $originalPlayer2Id = $player2->getId();

        $this->loginViaForm('opp_edit_rej_p1');

        $crawler = $this->client->request('GET', "/brackets/{$bracketId}/edit");
        $form = $crawler->selectButton('Save Changes')->form([
            'opponent_username' => 'opp_edit_rej_disabled',
        ]);
        $this->client->submit($form);

        $this->assertSelectorTextContains('body', 'Pick an opponent from the list');

        $refetched = static::getContainer()->get(BracketRepository::class)->find($bracketId);
        $this->assertSame($originalPlayer2Id, $refetched->getPlayer2()->getId());
    }
}
