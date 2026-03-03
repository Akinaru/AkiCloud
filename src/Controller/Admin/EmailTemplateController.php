<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/email-templates')]
#[IsGranted('ROLE_ADMIN')]
class EmailTemplateController extends AbstractController
{
    #[Route('/', name: 'admin_email_template_index', methods: ['GET'])]
    public function index(): Response
    {
        throw $this->createNotFoundException('Module des modèles d\'emails désactivé.');
    }

    #[Route('/new', name: 'admin_email_template_new', methods: ['GET', 'POST'])]
    public function new(): Response
    {
        throw $this->createNotFoundException('Module des modèles d\'emails désactivé.');
    }

    #[Route('/{id}/edit', name: 'admin_email_template_edit', methods: ['GET', 'POST'])]
    public function edit(): Response
    {
        throw $this->createNotFoundException('Module des modèles d\'emails désactivé.');
    }

    #[Route('/{id}', name: 'admin_email_template_delete', methods: ['POST'])]
    public function delete(): Response
    {
        throw $this->createNotFoundException('Module des modèles d\'emails désactivé.');
    }
}
