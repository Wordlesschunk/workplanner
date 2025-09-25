<?php

namespace App\Command;

use App\Entity\CalendarEvent;
use App\Entity\ICSCalendarEvent;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:schedule-tasks',
    description: 'Add a short description for your command',
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
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $today = new \DateTime('');

        $tasks = $this->entityManager->getRepository(Task::class)->findAll();
        $itemsInCalendar = $this->entityManager->getRepository(ICSCalendarEvent::class)->findAllICSEventsInDay($today);

        $workDayStart = (clone $today)->setTime(8, 0);
        $workDayEnd = (clone $today)->setTime(17, 0);

        $freeTimeSlots = [];
        $currentTime = clone $workDayStart;

        foreach ($itemsInCalendar as $event) {
            if ($event->getStartDateTime() > $currentTime) {
                $freeTimeSlots[] = [
                    'start' => clone $currentTime,
                    'end' => clone $event->getStartDateTime(),
                ];
            }
            $currentTime = clone $event->getEndDateTime();
        }

        if ($currentTime < $workDayEnd) {
            $freeTimeSlots[] = [
                'start' => clone $currentTime,
                'end' => clone $workDayEnd,
            ];
        }

        foreach ($freeTimeSlots as $freeTimeSlot) {
            $calendarEvent = new CalendarEvent();
            $calendarEvent->setStartDateTime($freeTimeSlot['start']);
            $calendarEvent->setEndDateTime($freeTimeSlot['end']);
            $calendarEvent->setTitle('FREE');
            $calendarEvent->setDescription('NULL');

            $this->entityManager->persist($calendarEvent);
            $this->entityManager->flush();
        }

        $io->success('Well done!');

        return Command::SUCCESS;
    }
}
