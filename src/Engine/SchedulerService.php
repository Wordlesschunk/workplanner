<?php

declare(strict_types=1);

namespace App\Engine;

use App\Entity\CalendarEvent;

/**
 * Schedules a list of tasks (with durations in seconds) into a work calendar.
 * - Respects existing calendar events (no overlaps / double booking)
 * - Schedules only within working hours (09:00–17:00)
 * - Supports optional break time (in minutes) between tasks
 * - Spans multiple days as needed
 */
final class SchedulerService
{
    /** @var int Break between tasks in minutes */
    private int $breakMinutes = 0;

    /** @var CalendarEvent[] Existing calendar events to respect */
    private array $calendarEvents = [];

    /** Set break minutes between scheduled tasks */
    public function setBreakMinutes(int $minutes): void
    {
        $this->breakMinutes = max(0, $minutes);
    }

    /** Provide existing calendar events to avoid conflicts */
    public function setCalendarEvents(array $events): void
    {
        // Filter only CalendarEvent instances
        $this->calendarEvents = array_values(array_filter($events, fn($e) => $e instanceof CalendarEvent));
    }

    /**
     * @param array<int,array{title:string,duration:int}> $tasks Each task has title and duration (seconds)
     * @param \DateTimeInterface|null $startFrom Optional start date/time reference (defaults to now)
     * @return CalendarEvent[] Scheduled calendar event entities (not persisted)
     */
    public function scheduleTasks(array $tasks, ?\DateTimeInterface $startFrom = null): array
    {
        $now = $startFrom?->setTimezone(new \DateTimeZone(date_default_timezone_get())) ?? new \DateTimeImmutable('now');

        $scheduled = [];
        $pointer = $this->alignToWorkWindowStart($now);

        // Preprocess busy intervals by day (merged & clipped to work window)
        $busyByDay = $this->buildBusyMap($this->calendarEvents);

        foreach ($tasks as $index => $task) {
            $title = $task['title'] ?? ('Task '.($index+1));
            $remaining = (int)($task['duration'] ?? 0);
            if ($remaining <= 0) {
                continue; // skip invalid durations
            }

            // Add break before task if needed (except first)
            if ($this->breakMinutes > 0 && !empty($scheduled)) {
                $pointer = $pointer->modify("+{$this->breakMinutes} minutes");
            }

            // Allocate contiguous time for this task; if it doesn't fit in the current day, continue next day.
            while ($remaining > 0) {
                // Move pointer inside work window if outside
                $pointer = $this->ensureWithinWorkWindow($pointer);

                $dayKey = $pointer->format('Y-m-d');
                $freeIntervals = $this->computeFreeIntervalsForDay($pointer, $busyByDay[$dayKey] ?? []);

                // Find the current free interval that can contain pointer
                $allocatedThisLoop = false;
                foreach ($freeIntervals as [$freeStart, $freeEnd]) {
                    if ($pointer < $freeStart) {
                        // Jump forward into the next free window
                        $pointer = $freeStart;
                    }
                    if ($pointer >= $freeStart && $pointer < $freeEnd) {
                        $available = $freeEnd->getTimestamp() - $pointer->getTimestamp();
                        $chunk = min($available, $remaining);
                        if ($chunk <= 0) {
                            continue;
                        }
                        // If this is the first part of the task, create the event and extend if it spans multiple days
                        if (!isset($currentEvent)) {
                            $eventStart = new \DateTime($pointer->format('Y-m-d H:i:s'));
                            $currentEvent = CalendarEvent::create($title, $eventStart, new \DateTime($pointer->format('Y-m-d H:i:s')));
                        }

                        $pointer = $pointer->modify("+{$chunk} seconds");
                        $remaining -= $chunk;
                        // Update end time of currentEvent
                        $currentEventEnd = new \DateTime($pointer->format('Y-m-d H:i:s'));
                        // Reflection not needed, recreate via factory by keeping start - but CalendarEvent has private props.
                        // Instead, we keep the object and set via reflection is not allowed. Workaround: accumulate and only finalize when task done.
                        $tmpEnd = $currentEventEnd; // placeholder
                        $this->setEventEndTime($currentEvent, $tmpEnd);

                        $allocatedThisLoop = true;

                        if ($remaining <= 0) {
                            // finalize completed task segment
                            $scheduled[] = $currentEvent;
                            unset($currentEvent);
                        } else {
                            // If we consumed the entire free interval but still have remaining time,
                            // close this segment and continue in the next free interval/day.
                            if ($chunk === $available && isset($currentEvent)) {
                                $scheduled[] = $currentEvent;
                                unset($currentEvent);
                            }
                        }
                        break; // break freeIntervals loop to reevaluate remaining
                    }
                }

                if (!$allocatedThisLoop) {
                    // Close any open segment before jumping to the next day
                    if (isset($currentEvent)) {
                        $scheduled[] = $currentEvent;
                        unset($currentEvent);
                    }
                    // Move to next day at 09:00
                    $pointer = $this->nextWorkDayStart($pointer);
                }
            }
        }

        return $scheduled;
    }

    private function alignToWorkWindowStart(\DateTimeInterface $from): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromInterface($from);
        $dayStart = $dt->setTime(9, 0, 0);
        $dayEnd = $dt->setTime(17, 0, 0);

        if ($dt < $dayStart) {
            return $dayStart;
        }
        if ($dt >= $dayEnd) {
            return $this->nextWorkDayStart($dt);
        }
        return $dt;
    }

    private function ensureWithinWorkWindow(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        $dayStart = $dt->setTime(9, 0, 0);
        $dayEnd = $dt->setTime(17, 0, 0);
        if ($dt < $dayStart) {
            return $dayStart;
        }
        if ($dt >= $dayEnd) {
            return $this->nextWorkDayStart($dt);
        }
        return $dt;
    }

    private function nextWorkDayStart(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        // Simple next day 09:00; does not skip weekends for simplicity (requirement didn't specify)
        $next = $dt->modify('+1 day');
        return $next->setTime(9, 0, 0);
    }

    /**
     * Build a map of busy intervals by YYYY-MM-DD, merged and clipped to work window.
     * @param CalendarEvent[] $events
     * @return array<string,array{0:\DateTimeImmutable,1:\DateTimeImmutable}[]> Map day => list of [start,end]
     */
    private function buildBusyMap(array $events): array
    {
        $map = [];
        foreach ($events as $ev) {
            $start = \DateTimeImmutable::createFromMutable($ev->getStartTime());
            $end = \DateTimeImmutable::createFromMutable($ev->getEndTime());
            if ($end <= $start) continue;

            // Iterate day-by-day and clip to 09:00–17:00 daily windows
            $day = $start->setTime(0, 0, 0);
            $endDay = $end->setTime(0, 0, 0);
            while ($day <= $endDay) {
                $windowStart = $day->setTime(9, 0, 0);
                $windowEnd = $day->setTime(17, 0, 0);
                $intervalStart = $this->dtMax($start, $windowStart);
                $intervalEnd = $this->dtMin($end, $windowEnd);
                if ($intervalEnd > $intervalStart) {
                    $key = $day->format('Y-m-d');
                    $map[$key][] = [$intervalStart, $intervalEnd];
                }
                $day = $day->modify('+1 day');
            }
        }

        // Merge overlapping intervals per day and sort
        foreach ($map as $key => $list) {
            usort($list, fn($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($list as [$s, $e]) {
                if (empty($merged)) {
                    $merged[] = [$s, $e];
                } else {
                    [$ls, $le] = $merged[count($merged)-1];
                    if ($s <= $le) {
                        // overlap/adjacent
                        $merged[count($merged)-1] = [$ls, ($le > $e ? $le : $e)];
                    } else {
                        $merged[] = [$s, $e];
                    }
                }
            }
            $map[$key] = $merged;
        }

        return $map;
    }

    /**
     * Compute free intervals for the given day containing $reference.
     * @param array{0:\DateTimeImmutable,1:\DateTimeImmutable}[] $busyIntervals
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}[]
     */
    private function computeFreeIntervalsForDay(\DateTimeImmutable $reference, array $busyIntervals): array
    {
        $dayStart = $reference->setTime(9, 0, 0);
        $dayEnd = $reference->setTime(17, 0, 0);
        $free = [];

        // If a break is configured, dilate busy intervals by +/- break to respect buffer before/after events
        if ($this->breakMinutes > 0 && !empty($busyIntervals)) {
            $adjusted = [];
            foreach ($busyIntervals as [$bs, $be]) {
                // Expand and clip to day window
                $as = $bs->modify('-' . $this->breakMinutes . ' minutes');
                $ae = $be->modify('+' . $this->breakMinutes . ' minutes');
                if ($as < $dayStart) { $as = $dayStart; }
                if ($ae > $dayEnd) { $ae = $dayEnd; }
                if ($ae > $as) {
                    $adjusted[] = [$as, $ae];
                }
            }
            // Merge adjusted intervals
            usort($adjusted, fn($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($adjusted as [$s, $e]) {
                if (empty($merged)) {
                    $merged[] = [$s, $e];
                } else {
                    [$ls, $le] = $merged[count($merged)-1];
                    if ($s <= $le) {
                        $merged[count($merged)-1] = [$ls, ($le > $e ? $le : $e)];
                    } else {
                        $merged[] = [$s, $e];
                    }
                }
            }
            $busyIntervals = $merged;
        }

        $cursor = $dayStart;

        foreach ($busyIntervals as [$bs, $be]) {
            if ($bs > $cursor) {
                $free[] = [$cursor, $this->dtMin($bs, $dayEnd)];
            }
            $cursor = ($cursor > $be ? $cursor : $be);
            if ($cursor >= $dayEnd) break;
        }

        if ($cursor < $dayEnd) {
            $free[] = [$cursor, $dayEnd];
        }

        return $free;
    }

    /**
     * Since CalendarEvent has private properties without setters, we use reflection to update end time on the instance
     * we created via factory. This keeps persistence concerns outside of this service.
     */
    private function setEventEndTime(CalendarEvent $event, \DateTime $end): void
    {
        $ref = new \ReflectionClass($event);
        if ($ref->hasProperty('endTime')) {
            $prop = $ref->getProperty('endTime');
            $prop->setAccessible(true);
            $prop->setValue($event, $end);
        }
    }

    private function dtMin(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    private function dtMax(\DateTimeImmutable $a, \DateTimeImmutable $b): \DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }
}
