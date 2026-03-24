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

    #[ORM\Column(nullable: true)]
    private ?int $firstPicker = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $player1 = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $player2 = null;

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

    public function getPlayer1(): ?User
    {
        return $this->player1;
    }

    public function setPlayer1(?User $player1): self
    {
        $this->player1 = $player1;
        return $this;
    }

    public function getPlayer2(): ?User
    {
        return $this->player2;
    }

    public function setPlayer2(?User $player2): self
    {
        $this->player2 = $player2;
        return $this;
    }

    public function getPlayer1Name(): string
    {
        return $this->player1?->getUsername() ?? 'Player 1';
    }

    public function getPlayer2Name(): string
    {
        return $this->player2?->getUsername() ?? 'Player 2';
    }

    public function getPlayerNumber(User $user): ?int
    {
        if ($this->player1 && $this->player1->getId() === $user->getId()) {
            return 1;
        }
        if ($this->player2 && $this->player2->getId() === $user->getId()) {
            return 2;
        }
        return null;
    }

    public function addGame(Game $game): self
    {
        if (!$this->games->contains($game)) {
            $this->games->add($game);
            $game->setBracket($this);
        }
        return $this;
    }

    public function getFirstPicker(): ?int
    {
        return $this->firstPicker;
    }

    public function setFirstPicker(?int $firstPicker): self
    {
        $this->firstPicker = $firstPicker;
        return $this;
    }

    /**
     * Determine which player (1 or 2) picks a given game.
     * Odd rounds use firstPicker for index 0, even rounds flip it.
     * Within a round, alternate by game index.
     */
    public function getPickerForGame(Game $game, int $gameIndex): int
    {
        $firstPicker = $this->firstPicker ?? 1;
        $roundNumber = $game->getRoundNumber();

        // Even rounds flip who goes first
        $roundPicker = ($roundNumber % 2 === 1) ? $firstPicker : ($firstPicker === 1 ? 2 : 1);

        // Alternate within the round
        return ($gameIndex % 2 === 0) ? $roundPicker : ($roundPicker === 1 ? 2 : 1);
    }
}
