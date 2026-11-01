<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\ProjectDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo pages: the home page shows what the signed-in user may do in each project (rbac_can), the project pages and
 * the reports page are guarded by the RbacKit request guard (403 without the route's permission).
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly ProjectDirectory $projects,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig', ['projects' => $this->projects->all()]);
    }

    /**
     * Asks the browser for the demo credentials (HTTP Basic), then goes back home.
     */
    #[Route('/sign-in', name: 'demo_sign_in')]
    public function signIn(): RedirectResponse
    {
        return $this->redirectToRoute('demo_home');
    }

    /**
     * The request guard answers 403 to members without the permission; the controller keeps its own check for users
     * outside the project (request_guard.skip_strangers), as an application would (or a 404 existence mask).
     */
    #[Route('/projects/{project}', name: 'demo_project', requirements: ['project' => '[a-z]+'])]
    public function project(string $project): Response
    {
        $context = $this->projects->find($project) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('permission:project.view', $context);

        return $this->render('demo/project.html.twig', ['project' => $context]);
    }

    #[Route('/projects/{project}/settings', name: 'demo_project_settings', requirements: ['project' => '[a-z]+'])]
    public function settings(string $project): Response
    {
        $context = $this->projects->find($project) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('permission:project.settings.view', $context);

        return $this->render('demo/project.html.twig', ['project' => $context, 'settings' => true]);
    }

    #[Route('/reports', name: 'demo_reports')]
    public function reports(): Response
    {
        return $this->render('demo/reports.html.twig', ['projects' => $this->projects->all()]);
    }
}
