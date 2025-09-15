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

    /**
     * Find random Flux entities
     * 
     * @param int $limit Number of random flux to return
     * @return Flux[] Returns an array of random Flux objects
     */
    public function findRandom(int $limit = 2): array
    {
        $allFlux = $this->findAll();
        
        if (count($allFlux) <= $limit) {
            return $allFlux;
        }
        
        // Shuffle and return limited results
        shuffle($allFlux);
        return array_slice($allFlux, 0, $limit);
    }
}
