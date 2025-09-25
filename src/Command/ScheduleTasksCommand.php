<?php

namespace App\Command;

use App\Entity\CalendarEvent;
use App\Entity\ICSCalendarEvent;
// use App\Entity\Task;
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
    description: 'Schedules tasks into free time over N days with min/max chunking and a unified break.',
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
        // Default is a week; change via --days=N (e.g., 14 for two weeks)
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

        // One unified break used everywhere (after meetings, between any two bookings, and between chunks)
        $BREAK_SECONDS = 900; // 15 minutes

        // Default chunk policy (task can override min/max)
        $DEFAULT_MIN_CHUNK = 1800; // 30 min
        $DEFAULT_MAX_CHUNK = 3600; // 60 min

        // How many days to schedule (starting today)
        $daysToSchedule = max(1, (int) $input->getOption('days'));

        // Today / now (Europe/London), immutable to avoid accidental mutation.
        $today = new \DateTimeImmutable('today');
        $now   = new \DateTimeImmutable('now');

        // ---------- SAMPLE TASKS (replace with repo later) ----------
        $tasks = [
            [
                'id' => 10,
                'name' => 'Deep Work Block',
                'priority' => 'high',
                'required_duration_seconds' => 6 * 3600, // 6h total
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800,  // 30m
                'max_chunk_seconds' => 3600,  // 60m
            ],
            [
                'id' => 11,
                'name' => 'Email Sweep',
                'priority' => 'normal',
                'required_duration_seconds' => 12600, // 3.5h
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800,  // 30m
                'max_chunk_seconds' => 3600,  // 60m
            ],
            [
                'id' => 12,
                'name' => 'Workout',
                'priority' => 'high',
                'required_duration_seconds' => 2700, // 45m
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800, // 30m
                'max_chunk_seconds' => 3600, // 60m
            ],
        ];

        // Precompute workday times
        [$wsH, $wsM] = array_map('intval', explode(':', $WORK_START));
        [$weH, $weM] = array_map('intval', explode(':', $WORK_END));

        // Track all bookings across the whole range
        $allBookings = [];

        for ($d = 0; $d < $daysToSchedule; $d++) {
            $day = $today->modify("+$d day");

            // Workday bounds for this day
            $workDayStart = $day->setTime($wsH, $wsM, 0);
            $workDayEnd   = $day->setTime($weH, $weM, 0);

            if ($workDayEnd <= $workDayStart) {
                $io->warning("Skipping {$day->format('Y-m-d')}: invalid workday window.");
                continue;
            }

            // Only schedule the remainder of *today*. For future days, use full workday.
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

            // Build free slots for this day (clamped to window, unified break added after busy items)
            $freeTimeSlots = $this->buildFreeSlotsForDay($day, $windowStart, $windowEnd, $BREAK_SECONDS);

            if (empty($freeTimeSlots)) {
                $io->writeln("No free time on {$day->format('Y-m-d')} within window.");
                continue;
            }

            // Schedule tasks into today's free slots, updating their completed time for carry-over
            $result = $this->scheduleTasksWithChunking(
                $tasks,
                $freeTimeSlots,
                $BREAK_SECONDS,
                $DEFAULT_MIN_CHUNK,
                $DEFAULT_MAX_CHUNK
            );

            // Persist today's bookings
            foreach ($result['bookings'] as $b) {
                // Skip if identical event exists (idempotency light)
                if ($this->calendarEventExists($b['name'], \DateTime::createFromImmutable($b['start']), \DateTime::createFromImmutable($b['end']))) {
                    continue;
                }

                $calendarEvent = new CalendarEvent();
                $calendarEvent->setStartDateTime(\DateTime::createFromImmutable($b['start']));
                $calendarEvent->setEndDateTime(\DateTime::createFromImmutable($b['end']));
                $calendarEvent->setTitle($b['name']);
                $calendarEvent->setDescription('Auto-scheduled');

                $this->entityManager->persist($calendarEvent);
            }
            $this->entityManager->flush();

            // Log today’s results
            if (!empty($result['bookings'])) {
                $io->section("Scheduled bookings for {$day->format('Y-m-d')}");
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
                $io->writeln("No tasks scheduled on {$day->format('Y-m-d')}.");
            }

            // Carry over: update $tasks’ completed_duration_seconds based on booked durations
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

            // Accumulate for final report
            $allBookings = array_merge($allBookings, $result['bookings']);

            // Refresh the tasks list to drop fully completed ones before the next day
            $tasks = array_values(array_filter($tasks, fn($t) => $this->remainingSeconds($t) > 0));

            // If everything is done early, bail out and ensure we do NOT print stale leftovers
            if (empty($tasks)) {
                $io->success("All tasks completed by {$day->format('Y-m-d')} — finishing early.");
                // No stale "Still remaining after range" — just finish.
                break;
            }
        }

        // ---------- Final summary ----------
        if (!empty($allBookings)) {
            $io->section('Summary: Scheduled across range');
            $io->writeln(sprintf('Total bookings: %d', count($allBookings)));
        } else {
            $io->warning('No bookings were created in the selected range.');
        }

        // Recompute true leftovers only now (after loop). If empty, nothing prints.
        $finalUnscheduled = $this->recomputeUnscheduledSnapshot($tasks);
        if (!empty($finalUnscheduled)) {
            $io->section('Still remaining after range');
            foreach ($finalUnscheduled as $u) {
                $io->writeln(sprintf(
                    '- %s: %d min remaining',
                    (string)($u['name'] ?? 'unnamed'),
                    (int) round($this->remainingSeconds($u) / 60)
                ));
            }
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    // ---------- Helpers ----------

    /**
     * Build free slots for a specific day within a given window [windowStart, windowEnd],
     * merging busy items and applying the unified break AFTER each busy block.
     * (No lunch hold — any lunch should be represented as an ICSCalendarEvent.)
     */
    private function buildFreeSlotsForDay(
        \DateTimeImmutable $day,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        int $BREAK_SECONDS
    ): array {
        /** @var ICSCalendarEvent[] $itemsInCalendar */
        $itemsInCalendar = $this->entityManager
            ->getRepository(ICSCalendarEvent::class)
            ->findAllICSEventsInDay($day);

        usort($itemsInCalendar, fn($a, $b) =>
            $a->getStartDateTime() <=> $b->getStartDateTime()
        );

        $busy = [];
        foreach ($itemsInCalendar as $e) {
            $s  = \DateTimeImmutable::createFromInterface($e->getStartDateTime());
            $en = \DateTimeImmutable::createFromInterface($e->getEndDateTime());

            if ($en <= $windowStart || $s >= $windowEnd) {
                continue; // outside window
            }

            // clamp to window
            if ($s < $windowStart) { $s = $windowStart; }
            if ($en > $windowEnd)  { $en = $windowEnd; }

            // merge overlaps
            if (!empty($busy)) {
                $lastKey = array_key_last($busy);
                $last = $busy[$lastKey];
                if ($s <= $last['end']) {
                    $busy[$lastKey]['end'] = ($en > $last['end']) ? $en : $last['end'];
                    continue;
                }
            }
            $busy[] = ['start' => $s, 'end' => $en];
        }

        // Build free slots, enforcing the unified break *after* each busy block
        $freeTimeSlots = [];
        $cursor = $windowStart;

        foreach ($busy as $block) {
            if ($block['start'] > $cursor) {
                $freeTimeSlots[] = [
                    'start' => $cursor,
                    'end'   => $block['start'],
                    'duration' => $block['start']->getTimestamp() - $cursor->getTimestamp(),
                ];
            }
            $afterMeeting = $block['end']->getTimestamp() + $BREAK_SECONDS;
            $cursor = (new \DateTimeImmutable('@' . $afterMeeting))->setTimezone($block['end']->getTimezone());
            if ($cursor >= $windowEnd) {
                break;
            }
        }

        if ($cursor < $windowEnd) {
            $freeTimeSlots[] = [
                'start' => $cursor,
                'end'   => $windowEnd,
                'duration' => $windowEnd->getTimestamp() - $cursor->getTimestamp(),
            ];
        }

        return $freeTimeSlots;
    }

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

    private function recomputeUnscheduledSnapshot(array $tasks): array
    {
        return array_values(array_filter($tasks, fn($t) => $this->remainingSeconds($t) > 0));
    }

    /**
     * Schedule multiple tasks with per-task chunking (min/max) and a unified break between ANY two bookings.
     *
     * - Finishes all chunks of task A before moving to task B.
     * - No padding; only the global $breakSec is enforced:
     *     • after meetings (handled when building free slots)
     *     • between any two bookings inside a slot
     *     • between chunks of the same task
     * - Final leftover may be < min_chunk_seconds to avoid leaving an unschedulable tail.
     *
     * @param array $tasks
     * @param array $timeslots
     * @param int   $breakSec           Global break in seconds between bookings/chunks.
     * @param int   $defaultMinChunk
     * @param int   $defaultMaxChunk
     * @return array{bookings: array<int, array>, unscheduled: array<int, array>}
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

            if ($effectiveEndTs <= $effectiveStartTs) {
                continue; // empty slot
            }

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
                if ($available < $seconds) {
                    continue;
                }
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

            // Guards
            $minChunk = max(1, $minChunk);
            $maxChunk = max($minChunk, $maxChunk);

            $chunksForThisTask = [];
            $nextEarliestStartTs = 0; // first chunk has no special constraint beyond slot cursor

            while ($remaining > 0) {
                // desired chunk up to maxChunk (but not more than remaining)
                $desired = min($remaining, $maxChunk);

                // If remaining >= minChunk, ensure chunk >= minChunk. If remaining < minChunk,
                // permit a "short final" chunk to avoid unschedulable tail.
                $chunkSize = ($remaining >= $minChunk) ? max($minChunk, $desired) : $remaining;

                // Try place chunk as-is
                $booking = $placeChunk($slots, $t, $chunkSize, $nextEarliestStartTs);

                if (!$booking) {
                    // If couldn't place, try step-down sizes (>= minChunk) in 5m decrements
                    $placed = false;
                    if ($remaining >= $minChunk) {
                        for ($try = min($desired, $maxChunk); $try >= $minChunk; $try -= 300) { // 300s = 5m
                            $booking = $placeChunk($slots, $t, $try, $nextEarliestStartTs);
                            if ($booking) { $placed = true; break; }
                        }
                    }
                    // If still not placed and remainder < minChunk, try to place the tiny final remainder
                    if (!$placed && $remaining < $minChunk) {
                        $booking = $placeChunk($slots, $t, $remaining, $nextEarliestStartTs);
                        if ($booking) { $placed = true; }
                    }

                    if (!$placed) {
                        // Could not place more chunks today — record remaining
                        $uns = $t;
                        $doneSoFar = array_sum(array_map(fn($b) => $b['duration'], $chunksForThisTask));
                        $uns['completed_duration_seconds'] = ($t['completed_duration_seconds'] ?? 0) + $doneSoFar;
                        $uns['remaining_seconds'] = max(0, $remaining);
                        $unscheduled[] = $uns;

                        // Keep the chunks already placed
                        $bookings = array_merge($bookings, $chunksForThisTask);
                        continue 2; // next task
                    }
                }

                // Successfully booked a chunk
                $chunksForThisTask[] = $booking;
                $remaining -= $booking['duration'];

                // Enforce the same global break before the next chunk of the same task
                if ($remaining > 0) {
                    $nextEarliestStartTs = $booking['end']->getTimestamp() + $breakSec;
                }
            }

            // Task fully scheduled today
            $bookings = array_merge($bookings, $chunksForThisTask);
        }

        return ['bookings' => $bookings, 'unscheduled' => $unscheduled];
    }
}
