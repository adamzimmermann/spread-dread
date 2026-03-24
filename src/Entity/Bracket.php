<?php

namespace App\Entity;

use App\Repository\BracketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BracketRepository::class)]
class Bracket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column]
    private int $year;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 100)]
    private string $player1Name = 'Player 1';

    #[ORM\Column(length: 100)]
    private string $player2Name = 'Player 2';

    /** @var Collection<int, Game> */
    #[ORM\OneToMany(targetEntity: Game::class, mappedBy: 'bracket', cascade: ['persist', 'remove'])]
    private Collection $games;

    public function __construct()
    {
        $this->games = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): self
    {
        $this->year = $year;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Game> */
    public function getGames(): Collection
    {
        return $this->games;
    }

    public function getPlayer1Name(): string
    {
        return $this->player1Name;
    }

    public function setPlayer1Name(string $player1Name): self
    {
        $this->player1Name = $player1Name;
        return $this;
    }

    public function getPlayer2Name(): string
    {
        return $this->player2Name;
    }

    public function setPlayer2Name(string $player2Name): self
    {
        $this->player2Name = $player2Name;
        return $this;
    }

    public function addGame(Game $game): self
    {
        if (!$this->games->contains($game)) {
            $this->games->add($game);
            $game->setBracket($this);
        }
        return $this;
    }
}
