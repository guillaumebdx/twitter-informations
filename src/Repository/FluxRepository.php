<?php

namespace App\Repository;

use App\Entity\Flux;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Flux>
 */
class FluxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Flux::class);
    }

    /**
     * Find all Flux entities ordered by creation date
     * 
     * @return Flux[] Returns an array of Flux objects
     */
    public function findAllOrderedByCreatedAt(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
