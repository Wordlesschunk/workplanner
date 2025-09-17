<?php

namespace App\Entity;

use App\Repository\CalendarEventICSRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CalendarEventICSRepository::class)]
class CalendarEventICS
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $uid = null;

    #[ORM\Column(length: 255)]
    private ?string $summary = null;

    #[ORM\Column(length: 2550)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTime $start = null;

    #[ORM\Column]
    private ?\DateTime $end = null;

    #[ORM\Column]
    private ?bool $isRecurring = null;

    #[ORM\Column(length: 255)]
    private ?string $recurringData = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string $uid): static
    {
        $this->uid = $uid;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStart(): ?\DateTime
    {
        return $this->start;
    }

    public function setStart(\DateTime $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?\DateTime
    {
        return $this->end;
    }

    public function setEnd(\DateTime $end): static
    {
        $this->end = $end;

        return $this;
    }

    public function isRecurring(): ?bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        $this->isRecurring = $isRecurring;

        return $this;
    }

    public function getRecurringData(): ?string
    {
        return $this->recurringData;
    }

    public function setRecurringData(?string $recurringData): void
    {
        $this->recurringData = $recurringData;
    }

}
