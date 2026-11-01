<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Ui;

use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\PermissionSet\PermissionSet;
use Nowo\RbacKitBundle\Routing\RbacRoutes;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_slice;
use function count;
use function implode;

/**
 * Builds the UiKit view models of the admin pages (REQ-UI-003): the permission set list, the catalogue and the set
 * form. The controller and the page contract test share it. View models carry labels, plain values (escaped by Twig)
 * and FormViews only. Stateless.
 */
final readonly class RbacPageFactory
{
    /** Routes listed per permission in the catalogue page (the rest is counted). */
    public const ROUTES_SHOWN = 3;

    /**
     * @param UrlGeneratorInterface $urls route URLs
     * @param ActionForms $actionForms CSRF forms of the delete actions (one token id per set)
     * @param RbacPages $pages layout / template overrides
     * @param string $catalogueDomain translation domain of permission and system set labels (`catalogue.translation_domain`)
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private ActionForms $actionForms,
        private RbacPages $pages = new RbacPages(),
        private string $catalogueDomain = 'messages',
    ) {
    }

    /**
     * Template of a page (`web_ui.templates.<page>` override, else the bundle template).
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
    }

    /**
     * Display name of a set: its stored name, else the translated label of a system set.
     */
    public function name(PermissionSet $set): Label|string
    {
        return $set->name ?? new Label($set->labelKey(), $this->catalogueDomain);
    }

    /**
     * Permission set list: code, name, kind badge, permission count and (with a usage provider) the assignment count.
     * Managers on a writable storage get "new", "edit" (unlocked sets) and "delete" (custom sets, POST + CSRF + confirm).
     *
     * @param array<string, PermissionSet> $sets
     * @param array<string, int>|null $usage assignment counts (null = no usage provider)
     */
    public function index(array $sets, ?array $usage, bool $canManage, bool $writable): ListPage
    {
        $rows = [];
        foreach ($sets as $code => $set) {
            $rows[] = [
                'code'        => $code,
                'name'        => $this->name($set),
                'kind'        => $set->locked ? 'locked' : ($set->system ? ($set->stored ? 'edited' : 'system') : 'custom'),
                'permissions' => count($set->permissions),
                'usage'       => $usage === null ? null : ($usage[$code] ?? 0),
                'editable'    => $canManage && $writable && !$set->locked,
                'deletable'   => $canManage && $writable && !$set->locked && !$set->system,
            ];
        }

        $columns = [
            new Column('code', RbacPages::label('column.code'), required: true),
            new Column('name', RbacPages::label('column.name')),
            new Column('kind', RbacPages::label('column.kind')),
            new Column('permissions', RbacPages::label('column.permissions'), align: 'end'),
        ];
        if ($usage !== null) {
            $columns[] = new Column('usage', RbacPages::label('column.usage'), align: 'end', priority: 2);
        }

        $actions = $canManage && $writable ? [Action::create($this->urls->generate(RbacRoutes::NEW), RbacPages::label('action.new'))] : [];

        return new ListPage(
            header: new PageHeader(RbacPages::label('sets.title'), RbacPages::label($writable ? 'sets.intro' : 'sets.intro_readonly'), $actions, tabs: $this->nav('sets')),
            columns: $columns,
            rows: $rows,
            rowActions: function (array $row): array {
                $actions = [];
                if ($row['editable'] === true) {
                    $actions[] = Action::edit($this->urls->generate(RbacRoutes::EDIT, ['code' => $row['code']]), RbacPages::label('action.edit'));
                }
                if ($row['deletable'] === true) {
                    $actions[] = $this->deleteAction((string) $row['code']);
                }

                return $actions;
            },
            empty: new EmptyState(RbacPages::label('sets.empty_title'), RbacPages::label('sets.empty')),
            caption: RbacPages::label('sets.title'),
            bundle: RbacPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'rbac-sets'],
            captionVisible: false,
        );
    }

    /**
     * Catalogue: every permission with its scope, group, routes (first {@see self::ROUTES_SHOWN}) and the sets holding it.
     *
     * @param array<string, Permission> $permissions
     * @param array<string, PermissionSet> $sets
     */
    public function catalogue(array $permissions, array $sets): ListPage
    {
        $rows = [];
        foreach ($permissions as $key => $permission) {
            $holders = [];
            foreach ($sets as $code => $set) {
                if ($set->grants($key)) {
                    $holders[] = $code;
                }
            }
            $routes = array_slice($permission->routes, 0, self::ROUTES_SHOWN);
            $more   = count($permission->routes) - count($routes);
            $rows[] = [
                'key'    => $key,
                'label'  => new Label($permission->labelKey(), $this->catalogueDomain),
                'scope'  => $permission->scope->value,
                'group'  => $permission->group,
                'routes' => implode(', ', $routes) . ($more > 0 ? ' +' . $more : ''),
                'sets'   => implode(', ', $holders),
            ];
        }

        return new ListPage(
            header: new PageHeader(RbacPages::label('catalogue.title'), RbacPages::label('catalogue.intro', ['%count%' => count($rows)]), tabs: $this->nav('catalogue')),
            columns: [
                new Column('key', RbacPages::label('column.key'), required: true),
                new Column('label', RbacPages::label('column.label')),
                new Column('scope', RbacPages::label('column.scope')),
                new Column('group', RbacPages::label('column.group'), priority: 3),
                new Column('routes', RbacPages::label('column.routes'), priority: 2),
                new Column('sets', RbacPages::label('column.sets'), priority: 2),
            ],
            rows: $rows,
            empty: new EmptyState(RbacPages::label('catalogue.empty_title'), RbacPages::label('catalogue.empty')),
            caption: RbacPages::label('catalogue.title'),
            bundle: RbacPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'rbac-catalogue'],
            captionVisible: false,
        );
    }

    /**
     * Create (`$set` null) or edit form; an editable custom set gets the delete zone.
     */
    public function form(FormView $form, ?PermissionSet $set): FormPage
    {
        $title = $set instanceof PermissionSet ? RbacPages::label('form.title_edit', ['%code%' => $set->code]) : RbacPages::label('form.title_new');
        $intro = RbacPages::label($set instanceof PermissionSet ? $set->system ? 'form.intro_system' : 'form.intro_edit' : ('form.intro_new'));
        $back  = $this->urls->generate(RbacRoutes::PERMISSION_SETS);

        return new FormPage(
            header: new PageHeader($title, $intro, tabs: $this->nav('sets'), back: new BackLink($back, RbacPages::label('form.back'), 'rbac-back')),
            form: $form,
            submitLabel: RbacPages::label($set instanceof PermissionSet ? 'action.save' : 'action.create'),
            cancelHref: $back,
            cancelLabel: RbacPages::label('action.cancel'),
            deleteAction: $set instanceof PermissionSet && !$set->system && !$set->locked ? $this->deleteAction($set->code) : null,
            bundle: RbacPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'rbac-form'],
            dangerZoneTitle: RbacPages::label('form.danger_title'),
            dangerZoneIntro: RbacPages::label('form.danger_intro'),
        );
    }

    /**
     * Area navigation (tabs of every page header).
     */
    public function nav(string $current): AreaNav
    {
        $items = [];
        foreach (['sets' => RbacRoutes::PERMISSION_SETS, 'catalogue' => RbacRoutes::CATALOGUE] as $area => $route) {
            $items[] = new AreaNavItem(RbacPages::label('nav.' . $area), $this->urls->generate($route), $area === $current, ['data-testid' => 'rbac-nav-' . $area]);
        }

        return new AreaNav($items, RbacPages::label('nav.label'));
    }

    private function deleteAction(string $code): Action
    {
        return new Action(
            kind: 'delete',
            label: RbacPages::label('action.delete'),
            href: $this->urls->generate(RbacRoutes::DELETE, ['code' => $code]),
            method: 'POST',
            confirm: new Confirm(RbacPages::label('confirm.delete_title'), RbacPages::label('confirm.delete', ['%code%' => $code]), RbacPages::label('action.delete')),
            variant: 'danger',
            attributes: ['data-testid' => 'rbac-delete'],
            form: $this->actionForms->delete(RbacRoutes::DELETE, $code)->createView(),
        );
    }
}
