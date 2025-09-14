<?php

namespace App\Controller;

use App\Entity\Flux;
use App\Form\FluxType;
use App\Repository\FluxRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux')]
class FluxController extends AbstractController
{
    #[Route('/', name: 'app_flux_index', methods: ['GET'])]
    public function index(FluxRepository $fluxRepository): Response
    {
        return $this->render('flux/index.html.twig', [
            'fluxes' => $fluxRepository->findAllOrderedByCreatedAt(),
        ]);
    }

    #[Route('/new', name: 'app_flux_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $flux = new Flux();
        $form = $this->createForm(FluxType::class, $flux);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($flux);
            $entityManager->flush();

            $this->addFlash('success', 'Le flux RSS a été créé avec succès.');

            return $this->redirectToRoute('app_flux_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('flux/new.html.twig', [
            'flux' => $flux,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_flux_show', methods: ['GET'])]
    public function show(Flux $flux): Response
    {
        return $this->render('flux/show.html.twig', [
            'flux' => $flux,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_flux_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Flux $flux, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(FluxType::class, $flux);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le flux RSS a été modifié avec succès.');

            return $this->redirectToRoute('app_flux_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('flux/edit.html.twig', [
            'flux' => $flux,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_flux_delete', methods: ['POST'])]
    public function delete(Request $request, Flux $flux, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$flux->getId(), $request->request->get('_token'))) {
            $entityManager->remove($flux);
            $entityManager->flush();

            $this->addFlash('success', 'Le flux RSS a été supprimé avec succès.');
        }

        return $this->redirectToRoute('app_flux_index', [], Response::HTTP_SEE_OTHER);
    }
}
