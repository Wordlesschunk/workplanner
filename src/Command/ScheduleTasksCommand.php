<?php

namespace App\Command;

use App\Entity\CalendarEvent;
use App\Entity\ICSCalendarEvent;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:schedule-tasks',
    description: 'Schedules tasks over N days with min/max chunking, a unified break, and re-run conflict resolution.',
)]
class ScheduleTasksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days to schedule (starting today)', 7);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ---------- HARD-CODED DEFAULTS ----------
        date_default_timezone_set('Europe/London');

        $WORK_START = '08:00';
        $WORK_END   = '17:00';

        // Unified break everywhere (after meetings, between any two bookings, and between chunks)
        $BREAK_SECONDS = 900; // 15 minutes

        // Default chunk policy (task can override min/max)
        $DEFAULT_MIN_CHUNK = 1800; // 30 min
        $DEFAULT_MAX_CHUNK = 3600; // 60 min

        $daysToSchedule = max(1, (int) $input->getOption('days'));

        $today = new \DateTimeImmutable('today');
        $now   = new \DateTimeImmutable('now');

        // ---------- SAMPLE TASKS (replace with repo later) ----------
        $tasks = [
            [
                'id' => 10,
                'name' => 'Deep Work Block',
                'priority' => 'high',
                'required_duration_seconds' => 6 * 3600, // 6h
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800,
                'max_chunk_seconds' => 3600,
            ],
            [
                'id' => 11,
                'name' => 'Email Sweep',
                'priority' => 'normal',
                'required_duration_seconds' => 12600, // 3.5h
                'completed_duration_seconds' => 0,
            ],
            [
                'id' => 12,
                'name' => 'Workout',
                'priority' => 'high',
                'required_duration_seconds' => 2700, // 45m
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800,
                'max_chunk_seconds' => 3600,
            ],
        ];

        // Precompute workday times
        [$wsH, $wsM] = array_map('intval', explode(':', $WORK_START));
        [$weH, $weM] = array_map('intval', explode(':', $WORK_END));

        $allBookings = [];

        for ($d = 0; $d < $daysToSchedule; $d++) {
            $day = $today->modify("+$d day");

            $workDayStart = $day->setTime($wsH, $wsM, 0);
            $workDayEnd   = $day->setTime($weH, $weM, 0);
            if ($workDayEnd <= $workDayStart) {
                $io->warning("Skipping {$day->format('Y-m-d')}: invalid workday window.");
                continue;
            }

            // Remainder-of-today logic
            $isToday = $day->format('Y-m-d') === $today->format('Y-m-d');
            $windowStart = $workDayStart;
            if ($isToday) {
                if ($now >= $workDayEnd) {
                    $io->writeln("Skipping {$day->format('Y-m-d')}: workday already ended.");
                    continue;
                }
                if ($now > $workDayStart && $now < $workDayEnd) {
                    $windowStart = $now;
                }
            }
            $windowEnd = $workDayEnd;

            // 1) ICS busy blocks for the day (clamped to window)
            $icsBusy = $this->fetchIcsBusyBlocks($day, $windowStart, $windowEnd);

            // 2) Previously auto-scheduled CalendarEvents for the day (in window)
            $planned = $this->fetchPlannedAutoEvents($windowStart, $windowEnd);

            // 3) Partition planned events into "conflicting" (overlaps ICS) vs "locked" (keep)
            [$lockedPlanned, $conflictingPlanned] = $this->partitionPlannedAgainstIcs($planned, $icsBusy);

            // 4) Remove conflicting planned events now (they will be rescheduled later today)
            foreach ($conflictingPlanned as $c) {
                $this->entityManager->remove($c['entity']);
            }
            if (!empty($conflictingPlanned)) {
                $this->entityManager->flush();
            }

            // 5) Reduce task remaining by the durations of locked planned events (so we don’t double schedule)
            $lockedDurByTitle = [];
            foreach ($lockedPlanned as $lp) {
                $lockedDurByTitle[$lp['title']] = ($lockedDurByTitle[$lp['title']] ?? 0) + $lp['duration'];
            }
            foreach ($tasks as &$t) {
                $title = (string)($t['name'] ?? '');
                $lockedDur = $lockedDurByTitle[$title] ?? 0;
                if ($lockedDur > 0) {
                    $t['completed_duration_seconds'] = (int) (($t['completed_duration_seconds'] ?? 0) + $lockedDur);
                }
            }
            unset($t);

            // 6) Build free slots from ICS busy + LOCKED planned (we keep them as busy)
            $freeTimeSlots = $this->buildFreeSlotsFromBusy($windowStart, $windowEnd, array_merge($icsBusy, $this->toBusyBlocks($lockedPlanned)), $BREAK_SECONDS);

            if (empty($freeTimeSlots)) {
                $io->writeln("No free time on {$day->format('Y-m-d')} within window.");
                continue;
            }

            // 7) Schedule remaining chunks into today's free slots
            $result = $this->scheduleTasksWithChunking(
                $tasks,
                $freeTimeSlots,
                $BREAK_SECONDS,
                $DEFAULT_MIN_CHUNK,
                $DEFAULT_MAX_CHUNK
            );

            // 8) Persist today’s new bookings
            foreach ($result['bookings'] as $b) {
                // Skip if identical event exists (idempotency light)
                if ($this->calendarEventExists($b['name'], \DateTime::createFromImmutable($b['start']), \DateTime::createFromImmutable($b['end']))) {
                    continue;
                }
                $calendarEvent = new CalendarEvent();
                $calendarEvent->setStartDateTime(\DateTime::createFromImmutable($b['start']));
                $calendarEvent->setEndDateTime(\DateTime::createFromImmutable($b['end']));
                $calendarEvent->setTitle($b['name']);
                $calendarEvent->setDescription('Auto-scheduled'); // consider storing a task_id here
                $this->entityManager->persist($calendarEvent);
            }
            $this->entityManager->flush();

            // 9) Log
            if (!empty($lockedPlanned)) {
                $io->section("Kept existing planned bookings for {$day->format('Y-m-d')} (no conflicts)");
                foreach ($lockedPlanned as $lp) {
                    $io->writeln(sprintf(
                        '[keep] %s: %s → %s (%dm)',
                        $lp['title'],
                        $lp['start']->format('Y-m-d H:i'),
                        $lp['end']->format('Y-m-d H:i'),
                        (int) round($lp['duration'] / 60)
                    ));
                }
            }
            if (!empty($conflictingPlanned)) {
                $io->section("Moved conflicting planned bookings on {$day->format('Y-m-d')}");
                foreach ($conflictingPlanned as $cp) {
                    $io->writeln(sprintf(
                        '[moved] %s: %s → %s (%dm)',
                        $cp['title'],
                        $cp['start']->format('Y-m-d H:i'),
                        $cp['end']->format('Y-m-d H:i'),
                        (int) round($cp['duration'] / 60)
                    ));
                }
            }
            if (!empty($result['bookings'])) {
                $io->section("New bookings for {$day->format('Y-m-d')}");
                foreach ($result['bookings'] as $b) {
                    $io->writeln(sprintf(
                        '[slot #%d] %s (%s): %s → %s (%dm)',
                        $b['slot_index'],
                        $b['name'],
                        $b['priority'],
                        $b['start']->format('Y-m-d H:i'),
                        $b['end']->format('Y-m-d H:i'),
                        (int) round($b['duration'] / 60)
                    ));
                }
            } else {
                $io->writeln("No new tasks scheduled on {$day->format('Y-m-d')}.");
            }

            // 10) Carry-over updates based on *new* bookings (locked ones were already counted)
            $bookedByTask = [];
            foreach ($result['bookings'] as $b) {
                $bookedByTask[$b['task_id']] = ($bookedByTask[$b['task_id']] ?? 0) + (int) $b['duration'];
            }
            foreach ($tasks as &$t) {
                $added = $bookedByTask[$t['id']] ?? 0;
                if ($added > 0) {
                    $t['completed_duration_seconds'] = (int) (($t['completed_duration_seconds'] ?? 0) + $added);
                }
            }
            unset($t);

            $allBookings = array_merge($allBookings, $result['bookings']);

            // Drop completed tasks before the next day
            $tasks = array_values(array_filter($tasks, fn($t) => $this->remainingSeconds($t) > 0));
            if (empty($tasks)) {
                $io->success("All tasks completed by {$day->format('Y-m-d')} — finishing early.");
                break;
            }
        }

        // ---------- Final summary ----------
        if (!empty($allBookings)) {
            $io->section('Summary: New bookings created across range');
            $io->writeln(sprintf('Total new bookings: %d', count($allBookings)));
        } else {
            $io->writeln('No new bookings were needed.');
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    // ==================== Busy / Planned helpers ====================

    /** Return ICS busy blocks for a given day window, clamped and merged. */
    private function fetchIcsBusyBlocks(\DateTimeImmutable $day, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        /** @var ICSCalendarEvent[] $itemsInCalendar */
        $itemsInCalendar = $this->entityManager
            ->getRepository(ICSCalendarEvent::class)
            ->findAllICSEventsInDay($day);

        usort($itemsInCalendar, fn($a, $b) => $a->getStartDateTime() <=> $b->getStartDateTime());

        $busy = [];
        foreach ($itemsInCalendar as $e) {
            $s  = \DateTimeImmutable::createFromInterface($e->getStartDateTime());
            $en = \DateTimeImmutable::createFromInterface($e->getEndDateTime());

            if ($en <= $windowStart || $s >= $windowEnd) continue;

            if ($s < $windowStart) { $s = $windowStart; }
            if ($en > $windowEnd)  { $en = $windowEnd; }

            if ($busy) {
                $lastKey = array_key_last($busy);
                $last = $busy[$lastKey];
                if ($s <= $last['end']) {
                    $busy[$lastKey]['end'] = ($en > $last['end']) ? $en : $last['end'];
                    continue;
                }
            }
            $busy[] = ['start' => $s, 'end' => $en];
        }
        return $busy;
    }

    /**
     * Fetch prior auto-scheduled CalendarEvents within [windowStart, windowEnd].
     * We detect them via description starting with 'Auto-scheduled'.
     */
    private function fetchPlannedAutoEvents(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $repo = $this->entityManager->getRepository(CalendarEvent::class);

        $qb = $repo->createQueryBuilder('e')
            ->where('e.startDateTime < :end')
            ->andWhere('e.endDateTime > :start')
            ->andWhere('e.description LIKE :desc')
            ->setParameters(new ArrayCollection([
                new Parameter('start', \DateTime::createFromImmutable($windowStart)),
                new Parameter('end', \DateTime::createFromImmutable($windowEnd)),
                new Parameter('desc', 'Auto-scheduled%'),
            ]));

        $events = $qb->getQuery()->getResult();

        $planned = [];
        foreach ($events as $ev) {
            /** @var CalendarEvent $ev */
            $s  = \DateTimeImmutable::createFromInterface($ev->getStartDateTime());
            $en = \DateTimeImmutable::createFromInterface($ev->getEndDateTime());
            $planned[] = [
                'entity'   => $ev,
                'title'    => (string)$ev->getTitle(),
                'start'    => $s,
                'end'      => $en,
                'duration' => max(0, $en->getTimestamp() - $s->getTimestamp()),
            ];
        }
        return $planned;
    }

    /** Split planned auto events into [locked, conflicting] against ICS busy blocks (simple overlap rule). */
    private function partitionPlannedAgainstIcs(array $planned, array $icsBusy): array
    {
        $locked = [];
        $conflicting = [];

        foreach ($planned as $p) {
            $overlaps = false;
            foreach ($icsBusy as $blk) {
                if ($this->intervalsOverlap($p['start'], $p['end'], $blk['start'], $blk['end'])) {
                    $overlaps = true; break;
                }
            }
            if ($overlaps) {
                $conflicting[] = $p;
            } else {
                $locked[] = $p;
            }
        }

        return [$locked, $conflicting];
    }

    private function intervalsOverlap(\DateTimeImmutable $aStart, \DateTimeImmutable $aEnd, \DateTimeImmutable $bStart, \DateTimeImmutable $bEnd): bool
    {
        return ($aStart < $bEnd) && ($aEnd > $bStart);
    }

    /** Convert planned events to busy-block shape for free-slot building. */
    private function toBusyBlocks(array $planned): array
    {
        $busy = [];
        foreach ($planned as $p) {
            $busy[] = ['start' => $p['start'], 'end' => $p['end']];
        }
        return $busy;
    }

    /**
     * Build free slots over a window given a set of busy blocks. Adds the unified break AFTER each block.
     * Busy blocks must be merged/non-overlapping ahead of time (ICS already merged; planned usually non-overlapping).
     */
    private function buildFreeSlotsFromBusy(
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        array $busyBlocks,
        int $BREAK_SECONDS
    ): array {
        // Merge all busy blocks (ICS + locked planned)
        usort($busyBlocks, fn($a, $b) => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($busyBlocks as $blk) {
            if (!$merged) { $merged[] = $blk; continue; }
            $last =& $merged[array_key_last($merged)];
            if ($blk['start'] <= $last['end']) {
                $last['end'] = ($blk['end'] > $last['end']) ? $blk['end'] : $last['end'];
            } else {
                $merged[] = $blk;
            }
        }

        $free = [];
        $cursor = $windowStart;

        foreach ($merged as $blk) {
            if ($blk['start'] > $cursor) {
                $free[] = [
                    'start' => $cursor,
                    'end'   => $blk['start'],
                    'duration' => $blk['start']->getTimestamp() - $cursor->getTimestamp(),
                ];
            }
            $after = $blk['end']->getTimestamp() + $BREAK_SECONDS;
            $cursor = (new \DateTimeImmutable('@' . $after))->setTimezone($blk['end']->getTimezone());
            if ($cursor >= $windowEnd) {
                break;
            }
        }

        if ($cursor < $windowEnd) {
            $free[] = [
                'start' => $cursor,
                'end'   => $windowEnd,
                'duration' => $windowEnd->getTimestamp() - $cursor->getTimestamp(),
            ];
        }

        return $free;
    }

    // ==================== Core scheduling helpers ====================

    private function priorityRank(string $p): int
    {
        return match (strtolower($p)) {
            'high' => 0,
            'normal' => 1,
            'low' => 2,
            default => 1,
        };
    }

    private function remainingSeconds(array $t): int
    {
        $req = max(0, (int)($t['required_duration_seconds'] ?? 0));
        $done = max(0, (int)($t['completed_duration_seconds'] ?? 0));
        return max(0, $req - $done);
    }

    private function calendarEventExists(string $title, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        $repo = $this->entityManager->getRepository(CalendarEvent::class);

        return (bool) $repo->createQueryBuilder('e')
            ->select('count(e.id)')
            ->where('e.title = :t')
            ->andWhere('e.startDateTime = :s')
            ->andWhere('e.endDateTime = :e')
            ->setParameters(new ArrayCollection([
                new Parameter('t', $title),
                new Parameter('s', $start),
                new Parameter('e', $end),
            ]))
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Schedule multiple tasks with per-task chunking (min/max) and a unified break between ANY two bookings.
     */
    private function scheduleTasksWithChunking(
        array $tasks,
        array $timeslots,
        int $breakSec,
        int $defaultMinChunk,
        int $defaultMaxChunk
    ): array {
        // 1) Filter: only tasks with remaining time
        $tasks = array_values(array_filter($tasks, fn($t) => $this->remainingSeconds($t) > 0));

        // 2) Sort by priority (high→low), then longer remaining first, then name
        usort($tasks, function ($a, $b) {
            $pa = $this->priorityRank($a['priority'] ?? 'normal');
            $pb = $this->priorityRank($b['priority'] ?? 'normal');
            if ($pa !== $pb) return $pa <=> $pb;

            $ra = $this->remainingSeconds($a);
            $rb = $this->remainingSeconds($b);
            if ($ra !== $rb) return $rb <=> $ra;

            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        // 3) Normalize slots into timestamp windows with a moving cursor
        $slots = [];
        foreach ($timeslots as $i => $slot) {
            $s = $slot['start'] instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($slot['start']) : new \DateTimeImmutable($slot['start']);
            $e = $slot['end']   instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($slot['end'])   : new \DateTimeImmutable($slot['end']);

            $effectiveStartTs = $s->getTimestamp(); // no edge padding
            $effectiveEndTs   = $e->getTimestamp();

            if ($effectiveEndTs <= $effectiveStartTs) continue;

            $slots[] = [
                'index'  => $i,
                'cursor' => $effectiveStartTs, // next booking start
                'endTs'  => $effectiveEndTs,   // hard end for this slot
                'tz'     => $s->getTimezone(),
            ];
        }

        $bookings = [];
        $unscheduled = [];

        // Helper: try to place a single chunk of $seconds for $task not before $notBeforeTs
        $placeChunk = function (array &$slots, array $task, int $seconds, int $notBeforeTs) use ($breakSec): ?array {
            foreach ($slots as &$ws) {
                $startTs = max($ws['cursor'], $notBeforeTs);
                $available = $ws['endTs'] - $startTs;
                if ($available < $seconds) continue;

                $endTs = $startTs + $seconds;

                $tz = $ws['tz'] ?? new \DateTimeZone(date_default_timezone_get());
                $start = (new \DateTimeImmutable('@' . $startTs))->setTimezone($tz);
                $end   = (new \DateTimeImmutable('@' . $endTs))->setTimezone($tz);

                // Advance slot cursor by global break after every booking
                $ws['cursor'] = $endTs + $breakSec;

                return [
                    'task_id'    => (int)($task['id'] ?? 0),
                    'name'       => (string)($task['name'] ?? ''),
                    'priority'   => (string)($task['priority'] ?? 'normal'),
                    'start'      => $start,
                    'end'        => $end,
                    'duration'   => $seconds,
                    'slot_index' => $ws['index'],
                ];
            }
            unset($ws);
            return null;
        };

        // 4) Schedule tasks fully (chunk-by-chunk) with the unified break
        foreach ($tasks as $t) {
            $remaining = $this->remainingSeconds($t);
            $minChunk  = (int)($t['min_chunk_seconds'] ?? $defaultMinChunk);
            $maxChunk  = (int)($t['max_chunk_seconds'] ?? $defaultMaxChunk);

            $minChunk = max(1, $minChunk);
            $maxChunk = max($minChunk, $maxChunk);

            $chunksForThisTask = [];
            $nextEarliestStartTs = 0;

            while ($remaining > 0) {
                $desired = min($remaining, $maxChunk);
                $chunkSize = ($remaining >= $minChunk) ? max($minChunk, $desired) : $remaining;

                $booking = $placeChunk($slots, $t, $chunkSize, $nextEarliestStartTs);

                if (!$booking) {
                    $placed = false;
                    if ($remaining >= $minChunk) {
                        for ($try = min($desired, $maxChunk); $try >= $minChunk; $try -= 300) {
                            $booking = $placeChunk($slots, $t, $try, $nextEarliestStartTs);
                            if ($booking) { $placed = true; break; }
                        }
                    }
                    if (!$placed && $remaining < $minChunk) {
                        $booking = $placeChunk($slots, $t, $remaining, $nextEarliestStartTs);
                        if ($booking) { $placed = true; }
                    }

                    if (!$placed) {
                        $uns = $t;
                        $doneSoFar = array_sum(array_map(fn($b) => $b['duration'], $chunksForThisTask));
                        $uns['completed_duration_seconds'] = ($t['completed_duration_seconds'] ?? 0) + $doneSoFar;
                        $uns['remaining_seconds'] = max(0, $remaining);
                        $unscheduled[] = $uns;

                        $bookings = array_merge($bookings, $chunksForThisTask);
                        continue 2;
                    }
                }

                $chunksForThisTask[] = $booking;
                $remaining -= $booking['duration'];

                if ($remaining > 0) {
                    $nextEarliestStartTs = $booking['end']->getTimestamp() + $breakSec;
                }
            }

            $bookings = array_merge($bookings, $chunksForThisTask);
        }

        return ['bookings' => $bookings, 'unscheduled' => $unscheduled];
    }
}
