<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/documentation')]
#[IsGranted('ROLE_ADMIN')]
class DocumentationController extends AbstractController
{
    #[Route('/technique', name: 'admin_documentation_technical')]
    public function technical(): Response
    {
        return $this->render('admin/documentation/technical.html.twig');
    }

    #[Route('/utilisation', name: 'admin_documentation_user')]
    public function user(): Response
    {
        return $this->render('admin/documentation/user.html.twig');
    }

    #[Route('/', name: 'admin_documentation_index')]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_documentation_user');
    }
}
