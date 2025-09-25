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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:schedule-tasks',
    description: 'Schedules tasks into today’s free time with per-task min/max chunking and breaks.',
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
        // Hard-coded defaults; no CLI options for now.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ---------- HARD-CODED DEFAULTS ----------
        date_default_timezone_set('Europe/London');

        $PADDING_SECONDS = 300;           // 5 min padding at slot edges & between ANY bookings
        $WORK_START = '08:00';
        $WORK_END = '17:00';

        // Default chunk policy (task can override with its own min/max/break)
        $DEFAULT_MIN_CHUNK = 1800;        // 30 min
        $DEFAULT_MAX_CHUNK = 3600;        // 60 min
        $DEFAULT_BREAK_BETWEEN_CHUNKS = 600; // 10 min break between chunks of the SAME task

        // Today (Europe/London), immutable to avoid accidental mutation.
        $today = new \DateTimeImmutable('tomorrow');

        // ---------- SAMPLE TASKS ----------
        // You can replace this with a repository call later.
        $tasks = [
            [
                'id' => 10,
                'name' => 'Deep Work Block',
                'priority' => 'high',
                'required_duration_seconds' => 3 * 3600, // 5h total
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800,  // 30m
                'max_chunk_seconds' => 3600,  // 60m
                'break_seconds' => 600,   // 10m between chunks
            ],
            [
                'id' => 11,
                'name' => 'Email Sweep',
                'priority' => 'normal',
                'required_duration_seconds' => 1800, // 30m
                'completed_duration_seconds' => 0,
                // inherits defaults (min=30m, max=60m, break=10m)
            ],
            [
                'id' => 12,
                'name' => 'Workout',
                'priority' => 'high',
                'required_duration_seconds' => 2700, // 45m
                'completed_duration_seconds' => 0,
                'min_chunk_seconds' => 1800, // 30m
                'max_chunk_seconds' => 3600, // 60m
                'break_seconds' => 300,  // 5m break if split (probably won’t split)
            ],
        ];

        // ---------- WORKDAY BOUNDS ----------
        [$wsH, $wsM] = array_map('intval', explode(':', $WORK_START));
        [$weH, $weM] = array_map('intval', explode(':', $WORK_END));
        $workDayStart = $today->setTime($wsH, $wsM, 0);
        $workDayEnd = $today->setTime($weH, $weM, 0);

        if ($workDayEnd <= $workDayStart) {
            $io->error('Workday end must be after start.');

            return Command::FAILURE;
        }

        // Optional: if running midday, don’t schedule in the past within the same day.
        $now = new \DateTimeImmutable('now');
        if ($now > $workDayStart && $now < $workDayEnd) {
            $workDayStart = $now;
        }

        // ---------- BUSY CALENDAR BLOCKS (clip + merge) ----------
        /** @var ICSCalendarEvent[] $itemsInCalendar */
        $itemsInCalendar = $this->entityManager
            ->getRepository(ICSCalendarEvent::class)
            ->findAllICSEventsInDay($today);

        usort($itemsInCalendar, fn ($a, $b) => $a->getStartDateTime() <=> $b->getStartDateTime()
        );

        $busy = [];
        foreach ($itemsInCalendar as $e) {
            $s = $e->getStartDateTime();
            $en = $e->getEndDateTime();

            if ($en <= $workDayStart || $s >= $workDayEnd) {
                continue; // outside workday
            }

            $s = max($s, $workDayStart);
            $en = min($en, $workDayEnd);

            if (!empty($busy)) {
                $lastKey = array_key_last($busy);
                $last = $busy[$lastKey];
                if ($s <= $last['end']) {
                    $busy[$lastKey]['end'] = max($last['end'], $en);
                    continue;
                }
            }
            $busy[] = ['start' => $s, 'end' => $en];
        }

        // ---------- OPTIONAL: LUNCH HOLD (12:30–13:30) ----------
        $lunchStart = $today->setTime(12, 30);
        $lunchEnd = $today->setTime(13, 30);
        if ($lunchEnd > $workDayStart && $lunchStart < $workDayEnd) {
            $busy[] = ['start' => max($lunchStart, $workDayStart), 'end' => min($lunchEnd, $workDayEnd)];
        }

        // Re-merge busy blocks after adding lunch
        if (!empty($busy)) {
            usort($busy, fn ($a, $b) => $a['start'] <=> $b['start']);
            $merged = [];
            foreach ($busy as $blk) {
                if (!$merged) {
                    $merged[] = $blk;
                    continue;
                }
                $last = &$merged[array_key_last($merged)];
                if ($blk['start'] <= $last['end']) {
                    $last['end'] = max($last['end'], $blk['end']);
                } else {
                    $merged[] = $blk;
                }
            }
            $busy = $merged;
        }

        // ---------- FREE SLOTS ----------
        $freeTimeSlots = [];
        $cursor = $workDayStart;

        foreach ($busy as $block) {
            if ($block['start'] > $cursor) {
                $freeTimeSlots[] = [
                    'start' => $cursor,
                    'end' => $block['start'],
                    'duration' => $block['start']->getTimestamp() - $cursor->getTimestamp(),
                ];
            }
            $cursor = max($cursor, $block['end']);
        }

        if ($cursor < $workDayEnd) {
            $freeTimeSlots[] = [
                'start' => $cursor,
                'end' => $workDayEnd,
                'duration' => $workDayEnd->getTimestamp() - $cursor->getTimestamp(),
            ];
        }

        if (empty($freeTimeSlots)) {
            $io->warning('No free time today within the defined workday.');

            return Command::SUCCESS;
        }

        // ---------- SCHEDULE WITH CHUNKING ----------
        $result = $this->scheduleTasksWithChunking(
            $tasks,
            $freeTimeSlots,
            $PADDING_SECONDS,
            $DEFAULT_MIN_CHUNK,
            $DEFAULT_MAX_CHUNK,
            $DEFAULT_BREAK_BETWEEN_CHUNKS
        );

        // ---------- PERSIST BOOKINGS ----------
        foreach ($result['bookings'] as $b) {
            // Skip if something identical already exists (idempotency light)
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

        // ---------- CONSOLE SUMMARY ----------
        if (!empty($result['bookings'])) {
            $io->section('Scheduled bookings');
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
            $io->warning('No tasks were scheduled.');
        }

        if (!empty($result['unscheduled'])) {
            $io->section('Unscheduled tasks (remaining today)');
            foreach ($result['unscheduled'] as $u) {
                $io->writeln(sprintf(
                    '- %s: %d min remaining (completed today: %d min)',
                    (string) ($u['name'] ?? 'unnamed'),
                    (int) round(($u['remaining_seconds'] ?? 0) / 60),
                    (int) round(((int) ($u['completed_duration_seconds'] ?? 0)) / 60),
                ));
            }
        }

        $io->success('Done.');

        return Command::SUCCESS;
    }

    // ---------- Helpers ----------

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
        $req = max(0, (int) ($t['required_duration_seconds'] ?? 0));
        $done = max(0, (int) ($t['completed_duration_seconds'] ?? 0));

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
     * Schedule multiple tasks with per-task chunking (min/max) and breaks between chunks.
     *
     * - Schedules tasks one-by-one (finish all chunks of task A before task B).
     * - Enforces padding at slot edges and between ANY two bookings.
     * - Enforces task-level break between chunks of the SAME task.
     * - Final leftover may be < min_chunk_seconds to avoid leaving an unschedulable tail.
     *
     * @return array{bookings: array<int, array>, unscheduled: array<int, array>}
     */
    private function scheduleTasksWithChunking(
        array $tasks,
        array $timeslots,
        int $paddingSec = 300,
        int $defaultMinChunk = 1800,
        int $defaultMaxChunk = 3600,
        int $defaultBreakBetweenChunks = 600,
    ): array {
        // 1) Filter: only tasks with remaining time
        $tasks = array_values(array_filter($tasks, fn ($t) => $this->remainingSeconds($t) > 0));

        // 2) Sort by priority (high→low), then longer remaining first, then name
        usort($tasks, function ($a, $b) {
            $pa = $this->priorityRank($a['priority'] ?? 'normal');
            $pb = $this->priorityRank($b['priority'] ?? 'normal');
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            $ra = $this->remainingSeconds($a);
            $rb = $this->remainingSeconds($b);
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        // 3) Normalize slots into timestamp windows with a moving cursor
        $slots = [];
        foreach ($timeslots as $i => $slot) {
            $s = $slot['start'] instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($slot['start']) : new \DateTimeImmutable($slot['start']);
            $e = $slot['end']   instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($slot['end']) : new \DateTimeImmutable($slot['end']);

            $effectiveStartTs = $s->getTimestamp() + $paddingSec;
            $effectiveEndTs = $e->getTimestamp() - $paddingSec;

            if ($effectiveEndTs <= $effectiveStartTs) {
                continue; // slot too small after padding
            }

            $slots[] = [
                'index' => $i,
                'cursor' => $effectiveStartTs, // next booking start
                'endTs' => $effectiveEndTs,   // hard end for this slot
                'tz' => $s->getTimezone(),
            ];
        }

        $bookings = [];
        $unscheduled = [];

        // Helper: try to place a single chunk of $seconds for $task not before $notBeforeTs
        $placeChunk = function (array &$slots, array $task, int $seconds, int $notBeforeTs) use ($paddingSec): ?array {
            foreach ($slots as &$ws) {
                $startTs = max($ws['cursor'], $notBeforeTs);
                $available = $ws['endTs'] - $startTs;
                if ($available < $seconds) {
                    continue;
                }
                $endTs = $startTs + $seconds;

                $tz = $ws['tz'] ?? new \DateTimeZone(date_default_timezone_get());
                $start = (new \DateTimeImmutable('@'.$startTs))->setTimezone($tz);
                $end = (new \DateTimeImmutable('@'.$endTs))->setTimezone($tz);

                // Advance slot cursor (plus padding) after the booking
                $ws['cursor'] = $endTs + $paddingSec;

                return [
                    'task_id' => (int) ($task['id'] ?? 0),
                    'name' => (string) ($task['name'] ?? ''),
                    'priority' => (string) ($task['priority'] ?? 'normal'),
                    'start' => $start,
                    'end' => $end,
                    'duration' => $seconds,
                    'slot_index' => $ws['index'],
                ];
            }
            unset($ws);

            return null;
        };

        // 4) Schedule each task fully (chunk-by-chunk) before moving to the next task
        foreach ($tasks as $t) {
            $remaining = $this->remainingSeconds($t);
            $minChunk = (int) ($t['min_chunk_seconds'] ?? $defaultMinChunk);
            $maxChunk = (int) ($t['max_chunk_seconds'] ?? $defaultMaxChunk);
            $breakSec = (int) ($t['break_seconds'] ?? $defaultBreakBetweenChunks);

            // Guards
            $minChunk = max(1, $minChunk);
            $maxChunk = max($minChunk, $maxChunk);

            $chunksForThisTask = [];
            $nextEarliestStartTs = 0; // no constraint for first chunk

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
                            if ($booking) {
                                $placed = true;
                                break;
                            }
                        }
                    }
                    // If still not placed and remainder < minChunk, try to place the tiny final remainder
                    if (!$placed && $remaining < $minChunk) {
                        $booking = $placeChunk($slots, $t, $remaining, $nextEarliestStartTs);
                        if ($booking) {
                            $placed = true;
                        }
                    }

                    if (!$placed) {
                        // Could not place more chunks today — record remaining
                        $uns = $t;
                        $doneSoFar = array_sum(array_map(fn ($b) => $b['duration'], $chunksForThisTask));
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

                // Enforce break before next chunk of the same task
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
