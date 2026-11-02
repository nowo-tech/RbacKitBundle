<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Controller;

use Nowo\RbacKitBundle\Form\PermissionSetType;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\PermissionSet\PermissionSet;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetException;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetUsageInterface;
use Nowo\RbacKitBundle\Routing\RbacRoutes;
use Nowo\RbacKitBundle\Security\AccessGuard;
use Nowo\RbacKitBundle\Security\RbacAdminAccessCheckerInterface;
use Nowo\RbacKitBundle\Ui\ActionForms;
use Nowo\RbacKitBundle\Ui\RbacPageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function is_array;
use function is_string;

/**
 * Admin UI of the permission sets (REQ-UI-002 / REQ-UI-003): the list (with assignment counts when a usage provider
 * exists), the read-only catalogue, and create / edit / delete for managers on a writable storage. Every mutation is a
 * POST with a CSRF token bound to the action and the set, audited through the manager's event; redirects are
 * generated paths only. The view area is enforced by the request listener; mutations require the manage area here.
 */
final class PermissionSetController extends AbstractController
{
    /** Accepted `code` route parameter (same alphabet as {@see PermissionSetManager::CODE_PATTERN}). */
    public const CODE_REQUIREMENT = '[a-z][a-z0-9_]{1,39}';

    /**
     * @param string $catalogueDomain translation domain of the permission and system set labels
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly PermissionSetRegistry $registry,
        private readonly PermissionSetManager $manager,
        private readonly PermissionCatalog $catalog,
        private readonly RbacPageFactory $pages,
        private readonly ActionForms $actionForms,
        private readonly FormFactoryInterface $formFactory,
        private readonly ?PermissionSetUsageInterface $usage = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly string $catalogueDomain = 'messages',
    ) {
    }

    /**
     * Permission set list.
     */
    #[Route('', name: RbacRoutes::PERMISSION_SETS, methods: ['GET'])]
    public function index(): Response
    {
        $sets = $this->registry->all();

        return $this->render($this->pages->template('index'), [
            'page' => $this->pages->index($sets, $this->usage?->usageCounts(array_keys($sets)), $this->canManage(), $this->registry->isWritable()),
        ]);
    }

    /**
     * Read-only catalogue: every permission, its scope and routes, and the sets that hold it.
     */
    #[Route('/catalogue', name: RbacRoutes::CATALOGUE, methods: ['GET'])]
    public function catalogue(): Response
    {
        return $this->render($this->pages->template('catalogue'), [
            'page' => $this->pages->catalogue($this->catalog->all(), $this->registry->all()),
        ]);
    }

    /**
     * New custom set; POST creates it (422 on an invalid submission).
     */
    #[Route('/new', name: RbacRoutes::NEW, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireMutable();
        $form = $this->form(['code' => '', 'name' => '', 'description' => null, 'permissions' => []], ActionForms::CREATE, ActionForms::NEW_TARGET, $this->generateUrl(RbacRoutes::NEW), true);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->data($form);
            try {
                $set = $this->manager->create($data['code'], $data['name'], $data['description'], $data['permissions'], $this->guard->user());
                $this->flash('success', 'flash.created', ['%code%' => $set->code]);

                return $this->redirectToRoute(RbacRoutes::PERMISSION_SETS);
            } catch (PermissionSetException $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        return $this->formResponse($form, null);
    }

    /**
     * Edit a set; POST saves it (422 on an invalid submission).
     */
    #[Route('/{code}/edit', name: RbacRoutes::EDIT, requirements: ['code' => self::CODE_REQUIREMENT], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $code): Response
    {
        $this->requireMutable();
        $set  = $this->editable($code);
        $form = $this->form([
            'name'        => $set->name ?? $this->translator?->trans($set->labelKey(), [], $this->catalogueDomain) ?? $set->code,
            'description' => $set->description,
            'permissions' => $set->permissions,
        ], ActionForms::EDIT, $set->code, $this->generateUrl(RbacRoutes::EDIT, ['code' => $set->code]), false);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $this->data($form);
            try {
                $this->manager->update($set->code, $data['name'], $data['description'], $data['permissions'], $this->guard->user());
                $this->flash('success', 'flash.updated', ['%code%' => $set->code]);

                return $this->redirectToRoute(RbacRoutes::PERMISSION_SETS);
            } catch (PermissionSetException $e) {
                $this->flash('error', $e->getMessage());
            }
        }

        return $this->formResponse($form, $set);
    }

    /**
     * Delete a custom set (POST + CSRF bound to the set).
     */
    #[Route('/{code}/delete', name: RbacRoutes::DELETE, requirements: ['code' => self::CODE_REQUIREMENT], methods: ['POST'])]
    public function delete(Request $request, string $code): RedirectResponse
    {
        $this->requireMutable();
        $set  = $this->editable($code);
        $form = $this->actionForms->delete(RbacRoutes::DELETE, $set->code);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid or missing CSRF token.');
        }

        try {
            $this->manager->delete($set->code, $this->guard->user());
            $this->flash('success', 'flash.deleted', ['%code%' => $set->code]);
        } catch (PermissionSetException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirectToRoute(RbacRoutes::PERMISSION_SETS);
    }

    private function canManage(): bool
    {
        return $this->guard->isGranted(RbacAdminAccessCheckerInterface::AREA_MANAGE);
    }

    /**
     * Manage area and a writable storage (else 404: the action does not exist on a read-only catalogue).
     */
    private function requireMutable(): void
    {
        $this->guard->denyUnlessGranted(RbacAdminAccessCheckerInterface::AREA_MANAGE);
        if (!$this->registry->isWritable()) {
            throw $this->createNotFoundException('Permission sets are read-only (storage.type: config).');
        }
    }

    private function editable(string $code): PermissionSet
    {
        $set = $this->registry->find($code);
        if (!$set instanceof PermissionSet || $set->locked) {
            throw $this->createNotFoundException('Unknown or locked permission set.');
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return FormInterface<mixed>
     */
    private function form(array $data, string $action, string $target, string $url, bool $withCode): FormInterface
    {
        return $this->formFactory->createNamed(ActionForms::formName($action, $target), PermissionSetType::class, $data, [
            'action'                        => $url,
            'method'                        => 'POST',
            'csrf_token_id'                 => ActionForms::tokenId($action, $target),
            'with_code'                     => $withCode,
            'permission_labels'             => array_map(static fn (Permission $permission): string => $permission->labelKey(), $this->catalog->all()),
            'permission_translation_domain' => $this->catalogueDomain,
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function formResponse(FormInterface $form, ?PermissionSet $set): Response
    {
        return $this->render($this->pages->template('form'), ['page' => $this->pages->form($form->createView(), $set, $this->manager->canDelete())], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return array{code: string, name: string, description: string|null, permissions: list<string>}
     */
    private function data(FormInterface $form): array
    {
        $data        = $form->getData();
        $data        = is_array($data) ? $data : [];
        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        $description = $data['description'] ?? null;

        return [
            'code'        => is_string($data['code'] ?? null) ? $data['code'] : '',
            'name'        => is_string($data['name'] ?? null) ? $data['name'] : '',
            'description' => is_string($description) ? $description : null,
            'permissions' => array_values(array_filter($permissions, is_string(...))),
        ];
    }

    /**
     * Adds a translated flash message (bundle domain).
     *
     * @param array<string, mixed> $params
     */
    private function flash(string $type, string $key, array $params = []): void
    {
        $this->addFlash($type, $this->translator?->trans($key, $params, NowoRbacKitBundle::TRANSLATION_DOMAIN) ?? $key);
    }
}
