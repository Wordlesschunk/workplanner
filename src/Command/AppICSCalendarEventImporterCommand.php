<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CalendarEventICS;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\VObject\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:calendar-ics:import',
    description: 'Imports calendar events from an ICS file',
)]
class AppICSCalendarEventImporterCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private EntityManagerInterface       $em
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        //todo Make me nice
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        //todo This will eventually come from the user maybe ?
        // possible to have this on a custom user activity event (when the user navigates/logins we kick off this process to make sure the content is in sync, why do this on a job if the user never uses the service?)
        $response = $this->client->request('GET', 'https://outlook.office365.com/owa/calendar/bfb19676106c49ff90acb1e1199a0397@wrenkitchens.com/00e8168b044a42f9ad6e8506a7a1895f7738480038597650141/calendar.ics');

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw new Exception('Cant read ics content');
        }

        $vcalendar = Reader::read($response->getContent());

        // Load all existing events in one query
        $existing = $this->em->getRepository(CalendarEventICS::class)
            ->createQueryBuilder('e')
            ->indexBy('e', 'e.uid')
            ->getQuery()
            ->getResult();

        $seenUids = [];

        foreach ($vcalendar->VEVENT as $vevent) {
            $uid = (string)$vevent->UID;
            $recurrenceId = isset($vevent->{'RECURRENCE-ID'})
                ? $vevent->{'RECURRENCE-ID'}->getDateTime()->format('Y-m-d H:i:s')
                : null;

            // We only want master events
            if ($recurrenceId !== null) {
                continue;
            }

            $seenKey = $uid . ($recurrenceId ? '#' . $recurrenceId : '');
            $seenUids[] = $seenKey;

            $event = $existing[$seenKey] ?? new CalendarEventICS();
            if (!isset($existing[$seenKey])) {
                $event->setUid($seenKey);
                $this->em->persist($event);
            }

            // Fill/update fields...
            $event->setSummary((string)$vevent->SUMMARY);
            $event->setDescription('SET ME!!');
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
