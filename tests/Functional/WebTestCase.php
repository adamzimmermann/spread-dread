<?php

namespace App\Tests\Functional;

use App\Entity\Bracket;
use App\Entity\Game;
use App\Entity\Team;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

abstract class WebTestCase extends BaseWebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $username = 'testuser', string $password = 'password'): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setPassword(password_hash($password, PASSWORD_BCRYPT));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    /**
     * Login by POSTing to /login and following the redirect.
     * After this call, the client has an authenticated session.
     */
    protected function loginViaForm(string $username = 'testuser', string $password = 'password'): void
    {
        $this->client->request('POST', '/login', [
            'username' => $username,
            'password' => $password,
        ]);
        // Follow the redirect to /brackets so the session cookie is set
        if ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }
    }

    protected function createBracket(User $player1, User $player2, int $firstPicker = 1): Bracket
    {
        $bracket = new Bracket();
        $bracket->setName('Test Bracket');
        $bracket->setYear(2025);
        $bracket->setPlayer1($player1);
        $bracket->setPlayer2($player2);
        $bracket->setFirstPicker($firstPicker);
        $this->em->persist($bracket);
        $this->em->flush();
        return $bracket;
    }

    protected function createTeam(string $name, int $seed, string $region = 'East'): Team
    {
        $team = new Team();
        $team->setName($name);
        $team->setSeed($seed);
        $team->setRegion($region);
        $team->setYear(2025);
        $this->em->persist($team);
        return $team;
    }

    protected function createGame(Bracket $bracket, Team $team1, Team $team2, int $round = 1, int $position = 1, ?string $region = 'East'): Game
    {
        $game = new Game();
        $game->setRoundNumber($round);
        $game->setBracketPosition($position);
        $game->setRegion($region);
        $game->setTeam1($team1);
        $game->setTeam2($team2);
        $bracket->addGame($game);
        $this->em->persist($game);
        $this->em->flush();
        return $game;
    }
}
