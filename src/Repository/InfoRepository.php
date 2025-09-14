<?php

namespace App\Repository;

use App\Entity\Info;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Info>
 */
class InfoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Info::class);
    }

    /**
     * Find paginated Info entities
     * 
     * @param int $page Current page number (starts at 1)
     * @param int $limit Number of items per page
     * @return array Contains 'paginator', 'totalItems', 'totalPages', 'currentPage'
     */
    public function findPaginated(int $page = 1, int $limit = 10): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $query = $this->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        $paginator = new Paginator($query);
        $totalItems = count($paginator);
        $totalPages = ceil($totalItems / $limit);

        return [
            'paginator' => $paginator,
            'totalItems' => $totalItems,
            'totalPages' => $totalPages,
            'currentPage' => $page,
        ];
    }
}
