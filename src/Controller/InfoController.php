<?php

namespace App\Controller;

use App\Entity\Info;
use App\Repository\InfoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/info')]
class InfoController extends AbstractController
{
    #[Route('/', name: 'app_info_index', methods: ['GET'])]
    public function index(Request $request, InfoRepository $infoRepository): Response
    {
        $page = $request->query->getInt('page', 1);
        $paginationData = $infoRepository->findPaginated($page, 10);

        return $this->render('info/index.html.twig', [
            'infos' => $paginationData['paginator'],
            'currentPage' => $paginationData['currentPage'],
            'totalPages' => $paginationData['totalPages'],
            'totalItems' => $paginationData['totalItems'],
        ]);
    }

    #[Route('/{id}', name: 'app_info_show', methods: ['GET'])]
    public function show(Info $info): Response
    {
        return $this->render('info/show.html.twig', [
            'info' => $info,
        ]);
    }
}
