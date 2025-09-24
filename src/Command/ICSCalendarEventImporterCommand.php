<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ICSCalendarEvent;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:ics-calendar:import',
    description: 'Imports calendar events from an ICS file (expanded with recurrences)',
)]
class ICSCalendarEventImporterCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command imports calendar events from an ICS feed, expanding recurring events into concrete instances.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // TODO: Move ICS URL to .env or argument/option
        $url = 'https://outlook.office365.com/owa/calendar/bfb19676106c49ff90acb1e1199a0397@wrenkitchens.com/00e8168b044a42f9ad6e8506a7a1895f7738480038597650141/calendar.ics';
        $response = $this->client->request('GET', $url);

        if (Response::HTTP_OK !== $response->getStatusCode()) {
            throw new \RuntimeException('Unable to fetch ICS feed.');
        }

        $vcalendar = Reader::read($response->getContent());

        // Load existing events, indexed by uid
        $existing = $this->em->getRepository(ICSCalendarEvent::class)
            ->createQueryBuilder('e')
            ->indexBy('e', 'e.uid')
            ->getQuery()
            ->getResult();

        $seenUids = [];

        // Process each master event (skip ones that are overrides)
        foreach ($vcalendar->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                continue; // we only expand masters
            }

            $iterator = new EventIterator($vcalendar, (string) $vevent->UID);

            // Expand up to 1 year in the future
            $until = new \DateTime('+1 year');

            while ($iterator->valid() && $iterator->getDTStart() < $until) {
                $occurrence = $iterator->getEventObject();

                // Skip cancelled occurrences
                if ('CANCELLED' === (string) ($occurrence->STATUS ?? '')) {
                    $iterator->next();
                    continue;
                }

                // UID + recurrence timestamp = unique key
                $recurrenceId = $iterator->getDTStart()->format('Y-m-d H:i:s');
                $seenKey = (string) $vevent->UID.'#'.$recurrenceId;
                $seenUids[] = $seenKey;

                $event = $existing[$seenKey] ?? new ICSCalendarEvent();
                if (!isset($existing[$seenKey])) {
                    $event->setUid($seenKey);
                    $this->em->persist($event);
                }

                // Update fields from occurrence
                $event->setSummary((string) ($occurrence->SUMMARY ?? ''));
                $event->setDescription((string) ($occurrence->DESCRIPTION ?? ''));
                $event->setStart(\DateTime::createFromImmutable($iterator->getDTStart()));
                $event->setEnd(\DateTime::createFromImmutable($iterator->getDTEnd()));
                $event->setIsRecurring(true);
                $event->setRecurringData((string) ($vevent->RRULE ?? ''));

                $iterator->next();
            }
        }

        // Remove old events not in feed anymore
        $seenUids = array_flip($seenUids);
        foreach ($existing as $uid => $event) {
            if (!isset($seenUids[$uid])) {
                $this->em->remove($event);
            }
        }

        $this->em->flush();

        $io->success('Calendar events imported successfully!');

        return Command::SUCCESS;
    }
}
