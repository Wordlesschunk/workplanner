<?php

declare(strict_types=1);

namespace App\Interface;

interface CalendarEventInterface
{
    public function getId();

    public function getTitle();

    public function getDescription();

    public function getStartDatetime();

    public function getEndDatetime();

    public function isLocked();
}
