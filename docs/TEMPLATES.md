# Templates

Pages of RbacKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.21+). The admin UI
(`web_ui.path_prefix`, default `/admin/rbac`, enabled with `web_ui.enabled: true`) has three page templates.
`Nowo\RbacKitBundle\Ui\RbacPageFactory` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the controller;
`Nowo\RbacKitBundle\Ui\RbacPages` resolves the layout (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`)
and the per-page overrides (`web_ui.templates.<page>`). Every page extends a UiKit scaffold. Contract test:
`tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict missing-translation rule,
plus "the editor identifier never reaches the HTML" and "delete forms carry their own token"). Twig namespace:
`@NowoRbacKitBundle` (views in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Forms](#forms)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Template contract](#template-contract)

## Pages

Every view model passes `bundle: 'NowoRbacKitBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoRbacKitBundle`)
and `layout: RbacPages::layout()`. Labels are `NowoRbacKitBundle` translation keys (`RbacPages::label()`); permission
and system set labels use `catalogue.translation_domain`. Every page header carries the area nav (`nav.sets`,
`nav.catalogue`).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Permission sets (`index`) | `admin/index.html.twig` | `page/list` | `ListPage` from `RbacPageFactory::index()`: columns code, name, kind (system / edited / custom / locked), permissions, assignments (with `storage.usage_provider`); header action `new` and row actions `edit` (unlocked sets) and `delete` (custom sets: POST, FormKit `CsrfOnlyType` with a token bound to the set, UiKit `Confirm`) for managers on a writable storage; empty state; root `data-testid="rbac-sets"` | `nowo_ui_list_row` (`_cells.html.twig`: kind badge) |
| Catalogue (`catalogue`) | `admin/catalogue.html.twig` | `page/list` | `ListPage` from `RbacPageFactory::catalogue()`: key, translated label, scope badge, group, the first three routes (+N), the sets holding it; empty state "every permission is denied"; root `data-testid="rbac-catalogue"` | `nowo_ui_list_row` (scope badge) |
| Create / edit (`form`) | `admin/form.html.twig` | `page/form` | `FormPage` from `RbacPageFactory::form()`: `PermissionSetType` FormView, back link, submit / cancel, and for custom sets the delete zone (same delete action as the list); root `data-testid="rbac-form"` | none (scaffold default: `form_start`, REQ-TWIG-003 child loop, submit, `form_end`) |

## Forms

`PermissionSetType` is a FormKit type (`#[FormKitConfig('rbac_kit')]` + `FormOptionsTrait`, REQ-TWIG-005 rule 11)
themed by the FormKit profile `nowo_form_kit.profiles.rbac_kit` that the bundle prepends (override it in the host:
[CONFIGURATION.md](CONFIGURATION.md#formkit-profile)). Its CSRF token id is bound to the action and the target
(`nowo_rbac/create/new`, `nowo_rbac/edit/<code>`); delete buttons use `nowo_rbac/delete/<code>`. The `/` separator
cannot appear in a set code, so a token of one set or action is refused by every other. No template contains a raw
`<form>`, `<input>`, `<textarea>` or `<select>` (`make check-no-raw-html-form`).

## Unique pages

None: every page extends a UiKit scaffold.

## Per-page overrides

`web_ui.templates.<page>` replaces one page template; extend the vendor one and override a block:

```twig
{# templates/admin/rbac/index.html.twig #}
{% extends '@!NowoRbacKitBundle/admin/index.html.twig' %}

{% block nowo_ui_list_after %}<p class="nowo-ui-muted">Ask the platform team before editing a system set.</p>{% endblock %}
```

```yaml
nowo_rbac_kit:
    web_ui:
        templates:
            index: 'admin/rbac/index.html.twig'
```

## Overriding templates (REQ-TWIG-001)

Full-file overrides live under `templates/bundles/NowoRbacKitBundle/<subpath>` and always win (`TwigPathsPass`
prepends that directory). **Freeze rule:** an application file at the override path hides every vendor change to that
path until you delete or merge it. Prefer the theme / layout, then `web_ui.templates`, then a full-file override. See
[USAGE.md](USAGE.md#overriding-templates) for the procedure and the `<subpath>` list.

## Bundle partials and assets

| Partial | Purpose |
| --- | --- |
| `admin/_cells.html.twig` | Row cells: `kind` (system / edited / custom / locked) and `scope` (global / context) as translated badges through the `badge_*` theme slots; labels translated with `nowo_ui_trans`; other values escaped, empty values as a muted dash |

The bundle ships no CSS / JS and no local copy of a UiKit partial (confirm, modal, flashes, empty state all come from
UiKit). Confirm dialogs need UiKit's `nowo-ui-confirm` script (included by the UiKit `scripts` partial).

## Template contract

Stable for 1.x: the page templates and the partial above, the `web_ui.templates` ids, the data blocks listed, the
view model fields used, and the `data-testid` hooks (`rbac-*`). Changes are recorded in [UPGRADING.md](UPGRADING.md)
under "Template contract".
