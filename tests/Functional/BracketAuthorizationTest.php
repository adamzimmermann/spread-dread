<?php

namespace App\Tests\Functional;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class BracketAuthorizationTest extends WebTestCase
{
    public function testOutsiderCannotViewAnotherUsersBracket(): void
    {
        $p1 = $this->createUser('authz_p1');
        $p2 = $this->createUser('authz_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_outsider');
        $this->loginViaForm('authz_outsider');

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', "/brackets/{$bracket->getId()}");
    }

    public function testOutsiderCannotEditAnotherUsersBracket(): void
    {
        $p1 = $this->createUser('authz_edit_p1');
        $p2 = $this->createUser('authz_edit_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_edit_outsider');
        $this->loginViaForm('authz_edit_outsider');

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', "/brackets/{$bracket->getId()}/edit");
    }

    public function testOutsiderCannotCallBracketApiEndpoints(): void
    {
        $p1 = $this->createUser('authz_api_p1');
        $p2 = $this->createUser('authz_api_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_api_outsider');
        $this->loginViaForm('authz_api_outsider');

        $id = $bracket->getId();
        foreach (["/api/brackets/$id/pull-spreads", "/api/brackets/$id/update-scores"] as $url) {
            $this->client->catchExceptions(false);
            try {
                $this->client->request('POST', $url, ['round' => 1]);
                $this->fail("Expected AccessDeniedException for $url");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPlayerCanViewTheirOwnBracket(): void
    {
        $p1 = $this->createUser('authz_own_p1');
        $p2 = $this->createUser('authz_own_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createTeam('Duke', 1);
        $this->loginViaForm('authz_own_p1');

        $this->client->request('GET', "/brackets/{$bracket->getId()}");
        $this->assertResponseIsSuccessful();
    }

    public function testAdminCanViewAnyBracket(): void
    {
        $p1 = $this->createUser('authz_admin_p1');
        $p2 = $this->createUser('authz_admin_p2');
        $bracket = $this->createBracket($p1, $p2);
        $this->createUser('authz_admin', 'password', null, true);
        $this->loginViaForm('authz_admin');

        $this->client->request('GET', "/brackets/{$bracket->getId()}");
        $this->assertResponseIsSuccessful();
    }
}
