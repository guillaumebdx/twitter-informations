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

    /**
     * Find the latest Info entities to check for duplicates
     * 
     * @param int $limit Number of latest entries to retrieve
     * @return Info[]
     */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if an Info with the same URL already exists
     * 
     * @param string $url The URL to check
     * @return bool
     */
    public function existsByUrl(string $url): bool
    {
        $count = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.url = :url')
            ->setParameter('url', $url)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
