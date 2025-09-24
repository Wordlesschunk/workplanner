<?php

namespace App\Entity;

use App\Repository\ICSCalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'tblCalendarEventICS')]
#[ORM\Entity(repositoryClass: ICSCalendarEventRepository::class)]
class ICSCalendarEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $uid;

    #[ORM\Column(length: 255)]
    private string $summary;

    #[ORM\Column(length: 2550)]
    private string $description;

    #[ORM\Column]
    private \DateTime $start;

    #[ORM\Column]
    private \DateTime $end;

    #[ORM\Column]
    private bool $isRecurring = false;

    #[ORM\Column(length: 255)]
    private string $recurringData;

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

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): void
    {
        $this->summary = $summary;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getStart(): \DateTime
    {
        return $this->start;
    }

    public function setStart(\DateTime $start): void
    {
        $this->start = $start;
    }

    public function getEnd(): \DateTime
    {
        return $this->end;
    }

    public function setEnd(\DateTime $end): void
    {
        $this->end = $end;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): void
    {
        $this->isRecurring = $isRecurring;
    }

    public function getRecurringData(): string
    {
        return $this->recurringData;
    }

    public function setRecurringData(string $recurringData): void
    {
        $this->recurringData = $recurringData;
    }
}
