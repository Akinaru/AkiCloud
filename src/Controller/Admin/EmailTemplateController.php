<?php

namespace App\Controller\Admin;

use App\Entity\EmailTemplate;
use App\Form\EmailTemplateType;
use App\Repository\EmailTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/email-templates')]
#[IsGranted('ROLE_ADMIN')]
class EmailTemplateController extends AbstractController
{
    #[Route('/', name: 'admin_email_template_index', methods: ['GET'])]
    public function index(EmailTemplateRepository $emailTemplateRepository): Response
    {
        return $this->render('admin/email_template/index.html.twig', [
            'email_templates' => $emailTemplateRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'admin_email_template_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $emailTemplate = new EmailTemplate();
        $form = $this->createForm(EmailTemplateType::class, $emailTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($emailTemplate);
            $entityManager->flush();

            $this->addFlash('success', 'Modèle d\'email créé avec succès.');

            return $this->redirectToRoute('admin_email_template_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/email_template/new.html.twig', [
            'email_template' => $emailTemplate,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_email_template_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EmailTemplate $emailTemplate, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(EmailTemplateType::class, $emailTemplate);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Modèle d\'email mis à jour.');

            return $this->redirectToRoute('admin_email_template_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/email_template/edit.html.twig', [
            'email_template' => $emailTemplate,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_email_template_delete', methods: ['POST'])]
    public function delete(Request $request, EmailTemplate $emailTemplate, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$emailTemplate->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($emailTemplate);
            $entityManager->flush();
            $this->addFlash('success', 'Modèle d\'email supprimé.');
        }

        return $this->redirectToRoute('admin_email_template_index', [], Response::HTTP_SEE_OTHER);
    }
}
