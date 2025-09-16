<?php

namespace App\Controller;

use App\Entity\ExecutionLog;
use App\Repository\ExecutionLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/execution-log')]
class ExecutionLogController extends AbstractController
{
    #[Route('/', name: 'app_execution_log_index', methods: ['GET'])]
    public function index(Request $request, ExecutionLogRepository $executionLogRepository): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $logs = $executionLogRepository->createQueryBuilder('e')
            ->orderBy('e.executedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $totalLogs = $executionLogRepository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = ceil($totalLogs / $limit);

        return $this->render('execution_log/index.html.twig', [
            'logs' => $logs,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalItems' => $totalLogs,
        ]);
    }

    #[Route('/{id}', name: 'app_execution_log_show', methods: ['GET'])]
    public function show(ExecutionLog $executionLog): Response
    {
        return $this->render('execution_log/show.html.twig', [
            'execution_log' => $executionLog,
        ]);
    }
}
