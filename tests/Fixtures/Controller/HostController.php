<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Controller;

use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Host pages of the kernel tests: an open home, a global page and two context pages; each prints the Twig checks.
 */
final class HostController extends AbstractController
{
    #[Route('/', name: 'host_home')]
    public function home(): Response
    {
        return $this->render('host/page.html.twig', ['project' => new Project('billing')]);
    }

    #[Route('/dashboard/summary', name: 'dashboard_summary')]
    public function summary(): Response
    {
        return new Response('summary');
    }

    #[Route('/projects/{id}', name: 'project_show')]
    public function project(string $id): Response
    {
        return new Response('project ' . $id);
    }

    #[Route('/projects/{id}/settings', name: 'project_settings')]
    public function settings(string $id): Response
    {
        return new Response('settings ' . $id);
    }
}
