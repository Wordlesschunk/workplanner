<?php

namespace App\Command;

use App\Entity\CalendarEvent;
use App\Repository\CalendarEventRepository;
use App\Repository\ICSCalendarEventRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scheduler:run',
    description: 'Schedules Tasks into CalendarEvents, avoiding ICS meetings and locked events.'
)]
class SchedulerCommand extends Command
{
    private const BUFFER_SECONDS = 900; // 15 minutes between tasks and around meetings

    public function __construct(
        private readonly TaskRepository $taskRepo,
        private readonly CalendarEventRepository $calendarEventRepo,
        private readonly ICSCalendarEventRepository $icsEventRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days into the future to schedule (default 7)',
                7
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');
        $now = new \DateTimeImmutable('now');
        $horizonEnd = $now->modify("+{$days} days");

        $output->writeln("<info>Starting scheduler (horizon = {$days} days)…</info>");

        // 0) Gather tasks
        $tasksAll = $this->taskRepo->findAll();
        $tasks = array_values(array_filter(
            $tasksAll,
            fn ($t) => $t->getCompletedDurationSeconds() < $t->getRequiredDurationSeconds()
        ));
        if (!$tasks) {
            $output->writeln('<comment>No tasks with remaining work. Nothing to schedule.</comment>');

            return Command::SUCCESS;
        }

        // Horizon start and end
        $hStart = $now;
        $hEnd = $horizonEnd;

        // 1) Collect busy intervals (ICS + locked CalendarEvents) with buffer applied
        $busy = [];
        $buffer = new \DateInterval('PT'.self::BUFFER_SECONDS.'S');

        foreach ($this->icsEventRepo->findAll() as $e) {
            $start = $e->getStartDateTime()->sub($buffer);
            $end = $e->getEndDateTime()->add($buffer);
            $busy[] = ['start' => $start, 'end' => $end];
        }

        foreach ($this->calendarEventRepo->findBy(['locked' => true]) as $e) {
            $start = \DateTimeImmutable::createFromMutable($e->getStartDateTime())->sub($buffer);
            $end = \DateTimeImmutable::createFromMutable($e->getEndDateTime())->add($buffer);
            $busy[] = ['start' => $start, 'end' => $end];
        }

        // normalize busy intervals within horizon
        $busy = array_values(array_filter(array_map(function ($i) use ($hStart, $hEnd) {
            $s = max($i['start'], $hStart);
            $e = min($i['end'], $hEnd);

            return $e > $s ? ['start' => $s, 'end' => $e] : null;
        }, $busy)));

        // 2) Build free slots (Mon–Fri, 08:00–17:00) within horizon
        [$workStartHour, $workEndHour] = [8, 17];
        $freeSlots = $this->buildFreeSlots($hStart, $hEnd, $busy, $workStartHour, $workEndHour);

        // 3) Sort tasks by priority (HIGH > MEDIUM > LOW), then by due date
        $prioOrder = ['HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
        usort($tasks, function ($a, $b) use ($prioOrder) {
            $pa = $prioOrder[$a->getPriority()] ?? 99;
            $pb = $prioOrder[$b->getPriority()] ?? 99;

            return $pa <=> $pb ?: ($a->getDueDate() <=> $b->getDueDate());
        });

        // 4) Schedule each task into blocks
        foreach ($tasks as $task) {
            $remaining = $task->getRequiredDurationSeconds() - $task->getCompletedDurationSeconds();
            if ($remaining <= 0) {
                continue;
            }

            $minChunk = max(300, (int) $task->getEventMinDurationSeconds()); // fallback 5 min
            $maxChunk = (int) $task->getEventMaxDurationSeconds();
            if ($maxChunk <= 0) {
                $maxChunk = 3600;
            } // fallback 60 min

            $scheduleAfter = \DateTimeImmutable::createFromMutable($task->getScheduleAfter());

            // If scheduleAfter is outside horizon, skip
            if ($scheduleAfter > $hEnd) {
                $output->writeln(sprintf(
                    '<comment>→ Task #%d "%s" starts after horizon, skipping.</comment>',
                    $task->getId(),
                    $task->getName()
                ));
                continue;
            }

            $output->writeln(sprintf(
                '→ Task #%d "%s": need %d min (chunks %d–%d min)',
                $task->getId(),
                $task->getName(),
                (int) ceil($remaining / 60),
                (int) ceil($minChunk / 60),
                (int) ceil($maxChunk / 60),
            ));

            $i = 0;
            while ($remaining > 0 && $i < count($freeSlots)) {
                $slot = $freeSlots[$i];
                $availStart = max($slot['start'], $scheduleAfter);
                $availEnd = min($slot['end'], $hEnd); // hard cutoff at horizon

                if ($availEnd <= $availStart) {
                    ++$i;
                    continue;
                }

                $availSecs = $availEnd->getTimestamp() - $availStart->getTimestamp();

                if ($availSecs < $minChunk && $remaining > $minChunk) {
                    ++$i;
                    continue;
                }

                // Decide block size
                if ($remaining < $minChunk) {
                    $blockSecs = min($minChunk, $availSecs);
                } else {
                    $blockSecs = min($maxChunk, $remaining, $availSecs);
                }

                if ($blockSecs <= 0) {
                    ++$i;
                    continue;
                }

                // Create event
                $ev = new CalendarEvent();
                $ev->setTitle('[Task] '.$task->getName());
                $ev->setDescription($task->getNotes() ?? '');
                $ev->setStartDateTime(\DateTime::createFromImmutable($availStart));
                $ev->setEndDateTime(\DateTime::createFromImmutable($availStart->modify("+{$blockSecs} seconds")));
                $ev->setLocked(false);

                $this->em->persist($ev);

                $remaining -= $blockSecs;

                // Split slot with buffer
                $allocStart = $availStart;
                $allocEnd = $availStart->modify("+{$blockSecs} seconds");
                $i = $this->splitSlot($freeSlots, $i, $allocStart, $allocEnd);
            }

            if ($remaining > 0) {
                $mins = (int) ceil($remaining / 60);
                $output->writeln(sprintf(
                    '<comment>   Could not schedule %d min within horizon for task #%d "%s".</comment>',
                    $mins, $task->getId(), $task->getName()
                ));
            } else {
                $output->writeln('   Scheduled fully ✅');
            }
        }

        $this->em->flush();
        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }

    private function buildFreeSlots(
        \DateTimeImmutable $hStart,
        \DateTimeImmutable $hEnd,
        array $busy,
        int $workStartHour,
        int $workEndHour,
    ): array {
        $busy = $this->mergeIntervals($busy);

        $slots = [];
        $cursor = (new \DateTimeImmutable($hStart->format('Y-m-d 00:00:00')));
        while ($cursor < $hEnd) {
            $dow = (int) $cursor->format('N'); // 1=Mon .. 7=Sun
            if ($dow >= 1 && $dow <= 5) {
                $dayStart = $cursor->setTime($workStartHour, 0, 0);
                $dayEnd = $cursor->setTime($workEndHour, 0, 0);

                $windowStart = max($dayStart, $hStart);
                $windowEnd = min($dayEnd, $hEnd);

                if ($windowEnd > $windowStart) {
                    $dayBusy = [];
                    foreach ($busy as $b) {
                        if ($b['end'] <= $windowStart || $b['start'] >= $windowEnd) {
                            continue;
                        }
                        $dayBusy[] = [
                            'start' => max($b['start'], $windowStart),
                            'end' => min($b['end'], $windowEnd),
                        ];
                    }
                    $dayBusy = $this->mergeIntervals($dayBusy);

                    $cursor2 = $windowStart;
                    foreach ($dayBusy as $b) {
                        if ($cursor2 < $b['start']) {
                            $slots[] = ['start' => $cursor2, 'end' => $b['start']];
                        }
                        $cursor2 = max($cursor2, $b['end']);
                    }
                    if ($cursor2 < $windowEnd) {
                        $slots[] = ['start' => $cursor2, 'end' => $windowEnd];
                    }
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $slots;
    }

    private function mergeIntervals(array $intervals): array
    {
        if (!$intervals) {
            return [];
        }
        usort($intervals, fn ($a, $b) => $a['start'] <=> $b['start']);

        $merged = [$intervals[0]];
        for ($i = 1; $i < count($intervals); ++$i) {
            $last = &$merged[count($merged) - 1];
            $cur = $intervals[$i];
            if ($cur['start'] <= $last['end']) {
                if ($cur['end'] > $last['end']) {
                    $last['end'] = $cur['end'];
                }
            } else {
                $merged[] = $cur;
            }
        }

        return $merged;
    }

    private function splitSlot(array &$slots, int $idx, \DateTimeImmutable $allocStart, \DateTimeImmutable $allocEnd): int
    {
        $slot = $slots[$idx];
        $new = [];

        if ($slot['start'] < $allocStart) {
            $new[] = ['start' => $slot['start'], 'end' => $allocStart];
        }

        $rightStart = $allocEnd->modify('+'.self::BUFFER_SECONDS.' seconds');
        if ($rightStart < $slot['end']) {
            $new[] = ['start' => $rightStart, 'end' => $slot['end']];
        }

        array_splice($slots, $idx, 1, $new);

        if (2 === count($new)) {
            return $idx + 1;
        }

        return $idx;
    }
}
