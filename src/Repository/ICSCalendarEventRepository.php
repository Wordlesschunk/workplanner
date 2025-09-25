<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ICSCalendarEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ICSCalendarEvent>
 */
class ICSCalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ICSCalendarEvent::class);
    }

    public function findAllICSEventsInDay(\DateTimeImmutable $date): array
    {
        $startOfDay = clone $date;
        $endOfDay = clone $date;

        return $this->createQueryBuilder('e')
            ->where('e.startDateTime >= :startTime')
            ->andWhere('e.endDateTime <= :endTime')
            ->setParameter('startTime', $startOfDay->setTime(0, 0, 0))
            ->setParameter('endTime', $endOfDay->setTime(23, 59, 59))
            ->orderBy('e.startDateTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
