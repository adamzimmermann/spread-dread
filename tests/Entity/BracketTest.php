<?php

namespace App\Tests\Entity;

use App\Entity\Bracket;
use App\Entity\Game;
use PHPUnit\Framework\TestCase;

class BracketTest extends TestCase
{
    public function testPickerForGameFirstPickerIsPlayer1OddRound(): void
    {
        $bracket = new Bracket();
        $bracket->setName('Test');
        $bracket->setYear(2025);
        $bracket->setFirstPicker(1);

        $game = new Game();
        $game->setRoundNumber(1); // odd round

        // Index 0 (even) -> firstPicker (1)
        $this->assertSame(1, $bracket->getPickerForGame($game, 0));
        // Index 1 (odd) -> other player (2)
        $this->assertSame(2, $bracket->getPickerForGame($game, 1));
        // Index 2 (even) -> firstPicker (1)
        $this->assertSame(1, $bracket->getPickerForGame($game, 2));
        // Index 3 (odd) -> other player (2)
        $this->assertSame(2, $bracket->getPickerForGame($game, 3));
    }

    public function testPickerForGameFirstPickerIsPlayer1EvenRound(): void
    {
        $bracket = new Bracket();
        $bracket->setName('Test');
        $bracket->setYear(2025);
        $bracket->setFirstPicker(1);

        $game = new Game();
        $game->setRoundNumber(2); // even round -> flips firstPicker

        // Index 0 (even) -> flipped picker (2)
        $this->assertSame(2, $bracket->getPickerForGame($game, 0));
        // Index 1 (odd) -> other player (1)
        $this->assertSame(1, $bracket->getPickerForGame($game, 1));
    }

    public function testPickerForGameFirstPickerIsPlayer2(): void
    {
        $bracket = new Bracket();
        $bracket->setName('Test');
        $bracket->setYear(2025);
        $bracket->setFirstPicker(2);

        $game = new Game();
        $game->setRoundNumber(1); // odd round

        // Index 0 -> firstPicker (2)
        $this->assertSame(2, $bracket->getPickerForGame($game, 0));
        // Index 1 -> other player (1)
        $this->assertSame(1, $bracket->getPickerForGame($game, 1));
    }

    public function testPickerForGameFirstPickerIsPlayer2EvenRound(): void
    {
        $bracket = new Bracket();
        $bracket->setName('Test');
        $bracket->setYear(2025);
        $bracket->setFirstPicker(2);

        $game = new Game();
        $game->setRoundNumber(2); // even round -> flips to 1

        // Index 0 -> flipped (1)
        $this->assertSame(1, $bracket->getPickerForGame($game, 0));
        // Index 1 -> other (2)
        $this->assertSame(2, $bracket->getPickerForGame($game, 1));
    }

    public function testPickerDefaultsToPlayer1WhenFirstPickerNull(): void
    {
        $bracket = new Bracket();
        $bracket->setName('Test');
        $bracket->setYear(2025);
        // firstPicker is null

        $game = new Game();
        $game->setRoundNumber(1);

        // Defaults to 1 as firstPicker
        $this->assertSame(1, $bracket->getPickerForGame($game, 0));
        $this->assertSame(2, $bracket->getPickerForGame($game, 1));
    }
}
