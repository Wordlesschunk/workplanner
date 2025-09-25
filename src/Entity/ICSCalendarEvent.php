<?php

declare(strict_types=1);

namespace App\Entity;

use App\Interface\CalendarEventInterface;
use App\Repository\ICSCalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'tblCalendarEventICS')]
#[ORM\Entity(repositoryClass: ICSCalendarEventRepository::class)]
class ICSCalendarEvent implements CalendarEventInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $uid;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 2550)]
    private string $description;

    #[ORM\Column]
    private \DateTimeImmutable $startDateTime;

    #[ORM\Column]
    private \DateTimeImmutable $endDateTime;

    #[ORM\Column]
    private bool $locked = true;

    public function getId(): int
    {
        return $this->id;
    }

    public function getUid(): string
    {
        return $this->uid;
    }

    public function setUid(string $uid): void
    {
        $this->uid = $uid;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getStartDateTime(): \DateTimeImmutable
    {
        return $this->startDateTime;
    }

    public function setStartDateTime(\DateTimeImmutable $startDateTime): void
    {
        $this->startDateTime = $startDateTime;
    }

    public function getEndDateTime(): \DateTimeImmutable
    {
        return $this->endDateTime;
    }

    public function setEndDateTime(\DateTimeImmutable $endDateTime): void
    {
        $this->endDateTime = $endDateTime;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): void
    {
        $this->locked = $locked;
    }
}
