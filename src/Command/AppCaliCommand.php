<?php

namespace App\Command;

use App\Entity\CalendarEventICS;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\VObject\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cal',
    description: 'Add a short description for your command',
)]
class AppCaliCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);


        $calendarPath = __DIR__ . '/../calendar.ics';

        if (!file_exists($calendarPath)) {
            throw new \InvalidArgumentException("File not found: {$calendarPath}");
        }

        $vcalendar = Reader::read(file_get_contents($calendarPath));

        // Load all existing events in one query
        $existing = $this->em->getRepository(CalendarEventICS::class)
            ->createQueryBuilder('e')
            ->indexBy('e', 'e.uid')
            ->getQuery()
            ->getResult();

        $seenUids = [];

        foreach ($vcalendar->VEVENT as $vevent) {
            $uid = (string) $vevent->UID;
            $recurrenceId = isset($vevent->{'RECURRENCE-ID'})
                ? $vevent->{'RECURRENCE-ID'}->getDateTime()->format('Y-m-d H:i:s')
                : null;

            // If you only want master events, skip overrides
            if ($recurrenceId !== null) {
                continue; // ignore this, handled by EventIterator later if needed
            }

            $seenKey = $uid . ($recurrenceId ? '#'.$recurrenceId : '');
            $seenUids[] = $seenKey;

            $event = $existing[$seenKey] ?? new CalendarEventICS();
            if (!isset($existing[$seenKey])) {
                $event->setUid($seenKey);
                $this->em->persist($event);
            }

            // Fill/update fields...
            $event->setSummary((string) $vevent->SUMMARY);
            $event->setDescription('NULL');
            $event->setDtStart(\DateTime::createFromImmutable($vevent->DTSTART->getDateTime()));
            $event->setStart(\DateTime::createFromImmutable($vevent->DTSTART->getDateTime()));
            $event->setEnd(\DateTime::createFromImmutable($vevent->DTEND->getDateTime()));

            if (isset($vevent->RRULE)) {
                $event->setIsRecurring(true);
                $event->setRecurringData($vevent->RRULE->getValue());
            } else {
                $event->setIsRecurring(false);
                $event->setRecurringData('null');
            }
        }


        // Delete events no longer present
        foreach ($existing as $uid => $event) {
            if (!in_array($uid, $seenUids, true)) {
                $this->em->remove($event);
            }
        }

        $this->em->flush();

        $io->success('Calendar Events Imported!');

        return Command::SUCCESS;
    }
}
