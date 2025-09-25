<?php

namespace App\Command;

use App\Entity\CalendarEvent;
use App\Entity\ICSCalendarEvent;
// use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:schedule-tasks',
    description: 'Schedules multiple tasks into today’s free time slots (hard-coded defaults).',
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
        // No CLI options/args — all defaults are hard-coded below.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ---------- HARD-CODED DEFAULTS ----------
        $PADDING_SECONDS = 300;   // 5 min
        $WORK_START = '08:00';
        $WORK_END = '17:00';

        // If you want a fixed timezone for everything, uncomment next line:
        // date_default_timezone_set('Europe/London');

        // Today (server tz). Use immutable to avoid accidental mutation.
        $today = new \DateTimeImmutable('today');

        // Sample tasks — replace with your repository fetch when ready.
        $tasks = [
            [
                'id' => 3,
                'name' => 'nap',
                'priority' => 'high',
                'required_duration_seconds' => 19800,
                'completed_duration_seconds' => 0,
            ],
            [
                'id' => 4,
                'name' => 'email sweep',
                'priority' => 'normal',
                'required_duration_seconds' => 1800,
                'completed_duration_seconds' => 0,
            ],
            [
                'id' => 5,
                'name' => 'workout',
                'priority' => 'high',
                'required_duration_seconds' => 2700, // 45 min
                'completed_duration_seconds' => 0,
            ],
        ];

        // ---- Workday bounds ----
        [$wsH, $wsM] = array_map('intval', explode(':', $WORK_START));
        [$weH, $weM] = array_map('intval', explode(':', $WORK_END));
        $workDayStart = $today->setTime($wsH, $wsM, 0);
        $workDayEnd = $today->setTime($weH, $weM, 0);

        if ($workDayEnd <= $workDayStart) {
            $io->error('Workday end must be after start.');

            return Command::FAILURE;
        }

        // ---- Busy calendar blocks (clip + merge) ----
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
                continue;
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

        // ---- Free slots ----
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

        // ---- Schedule tasks (no splitting) ----
        $result = $this->scheduleTasks($tasks, $freeTimeSlots, $PADDING_SECONDS);

        // ---- Persist bookings ----
        foreach ($result['bookings'] as $b) {
            $calendarEvent = new CalendarEvent();
            $calendarEvent->setStartDateTime(\DateTime::createFromImmutable($b['start']));
            $calendarEvent->setEndDateTime(\DateTime::createFromImmutable($b['end']));
            $calendarEvent->setTitle($b['name']);
            $calendarEvent->setDescription('Auto-scheduled');

            $this->entityManager->persist($calendarEvent);
        }
        $this->entityManager->flush();

        // ---- Console summary ----
        if (!empty($result['bookings'])) {
            $io->section('Scheduled bookings');
            foreach ($result['bookings'] as $b) {
                $io->writeln(sprintf(
                    '[slot #%d] %s (%s): %s → %s (%ds)',
                    $b['slot_index'],
                    $b['name'],
                    $b['priority'],
                    $b['start']->format('Y-m-d H:i'),
                    $b['end']->format('Y-m-d H:i'),
                    $b['duration']
                ));
            }
        } else {
            $io->warning('No tasks were scheduled.');
        }

        if (!empty($result['unscheduled'])) {
            $io->section('Unscheduled tasks');
            foreach ($result['unscheduled'] as $u) {
                $io->writeln(sprintf(
                    '- %s (needs %ds)',
                    (string) ($u['name'] ?? 'unnamed'),
                    $this->remainingSeconds($u)
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

    /**
     * Schedule multiple tasks into free timeslots (earliest fit, no splitting).
     *
     * @return array{bookings: array<int, array>, unscheduled: array<int, array>}
     */
    private function scheduleTasks(array $tasks, array $timeslots, int $paddingSec = 0): array
    {
        $tasks = array_values(array_filter($tasks, fn ($t) => $this->remainingSeconds($t) > 0));

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

        $slots = [];
        foreach ($timeslots as $i => $slot) {
            $s = $slot['start'] instanceof \DateTimeInterface ? $slot['start'] : new \DateTimeImmutable($slot['start']);
            $e = $slot['end']   instanceof \DateTimeInterface ? $slot['end'] : new \DateTimeImmutable($slot['end']);

            $effectiveStartTs = $s->getTimestamp() + $paddingSec;
            $effectiveEndTs = $e->getTimestamp() - $paddingSec;

            if ($effectiveEndTs <= $effectiveStartTs) {
                continue;
            }

            $slots[] = [
                'index' => $i,
                'cursor' => $effectiveStartTs,
                'endTs' => $effectiveEndTs,
                'tz' => $s->getTimezone(),
            ];
        }

        $bookings = [];
        $unscheduled = [];

        foreach ($tasks as $t) {
            $need = $this->remainingSeconds($t);
            $placed = false;

            foreach ($slots as &$ws) {
                $startTs = $ws['cursor'];
                $endTs = $startTs + $need;

                if ($endTs <= $ws['endTs']) {
                    $tz = $ws['tz'] ?? new \DateTimeZone(date_default_timezone_get());
                    $start = (new \DateTimeImmutable('@'.$startTs))->setTimezone($tz);
                    $end = (new \DateTimeImmutable('@'.$endTs))->setTimezone($tz);

                    $bookings[] = [
                        'task_id' => (int) ($t['id'] ?? 0),
                        'name' => (string) ($t['name'] ?? ''),
                        'priority' => (string) ($t['priority'] ?? 'normal'),
                        'start' => $start,
                        'end' => $end,
                        'duration' => $need,
                        'slot_index' => $ws['index'],
                    ];

                    $ws['cursor'] = $endTs + $paddingSec; // keep padding to next booking
                    $placed = true;
                    break;
                }
            }
            unset($ws);

            if (!$placed) {
                $unscheduled[] = $t;
            }
        }

        return ['bookings' => $bookings, 'unscheduled' => $unscheduled];
    }
}
