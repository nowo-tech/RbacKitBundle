# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/rbac-kit-bundle`  
**Last audited**: 2026-11-01

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **65**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/DependencyInjection/Compiler/CsrfProtectionPass.php` | Bundle and dependency injection | FR-SEC-002 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | Bundle and dependency injection | FR-BUNDLE-001, FR-UI-004 |
| `src/DependencyInjection/Compiler/UnauthenticatedAccessPass.php` | Bundle and dependency injection | FR-SEC-002 |
| `src/DependencyInjection/Configuration.php` | Bundle and dependency injection | FR-BUNDLE-001, FR-SEC-002 |
| `src/DependencyInjection/NowoRbacKitExtension.php` | Bundle and dependency injection | FR-BUNDLE-001, FR-SEC-002 |
| `src/NowoRbacKitBundle.php` | Bundle and dependency injection | FR-BUNDLE-001 |

## Catalogue

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Permission/ConfigPermissionProvider.php` | Catalogue | FR-CAT-002 |
| `src/Permission/Permission.php` | Catalogue | FR-CAT-001 |
| `src/Permission/PermissionCatalog.php` | Catalogue | FR-CAT-002 |
| `src/Permission/PermissionProviderInterface.php` | Catalogue | FR-CAT-002 |
| `src/Permission/PermissionScope.php` | Catalogue | FR-CAT-001 |

## Permission sets

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Doctrine/TablePrefixListener.php` | Permission sets | FR-SET-003 |
| `src/Entity/PermissionSetRecord.php` | Permission sets | FR-SET-003 |
| `src/Event/PermissionSetChangedEvent.php` | Permission sets | FR-SET-004 |
| `src/PermissionSet/ConfigPermissionSetStorage.php` | Permission sets | FR-SET-003 |
| `src/PermissionSet/DoctrinePermissionSetStorage.php` | Permission sets | FR-SET-003 |
| `src/PermissionSet/PermissionSet.php` | Permission sets | FR-SET-001 |
| `src/PermissionSet/PermissionSetException.php` | Permission sets | FR-SET-004 |
| `src/PermissionSet/PermissionSetManager.php` | Permission sets | FR-SET-004 |
| `src/PermissionSet/PermissionSetRegistry.php` | Permission sets | FR-SET-002 |
| `src/PermissionSet/PermissionSetStorageInterface.php` | Permission sets | FR-SET-003 |
| `src/PermissionSet/PermissionSetUsageInterface.php` | Permission sets | FR-SET-004 |
| `src/PermissionSet/StoredPermissionSet.php` | Permission sets | FR-SET-001 |

## Grants and contexts

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Context/ContextId.php` | Grants and contexts | FR-CTX-001 |
| `src/Context/ContextResolverInterface.php` | Grants and contexts | FR-CTX-001 |
| `src/Context/DefaultContextResolver.php` | Grants and contexts | FR-CTX-001 |
| `src/Context/NullRequestContextResolver.php` | Grants and contexts | FR-CTX-002 |
| `src/Context/RbacContextInterface.php` | Grants and contexts | FR-CTX-001 |
| `src/Context/RequestContextResolverInterface.php` | Grants and contexts | FR-CTX-002 |
| `src/Grant/Assignment.php` | Grants and contexts | FR-GRT-001 |
| `src/Grant/GrantProviderInterface.php` | Grants and contexts | FR-GRT-001 |
| `src/Grant/GrantResolver.php` | Grants and contexts | FR-GRT-002 |
| `src/Grant/NullGrantProvider.php` | Grants and contexts | FR-GRT-001 |

## Voter and entry points

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/EventListener/PermissionRequestListener.php` | Voter and entry points | FR-GRD-001 |
| `src/Menu/RbacMenuPermissionChecker.php` | Voter and entry points | FR-MNU-001 |
| `src/Security/BypassVetoInterface.php` | Voter and entry points | FR-VOT-002 |
| `src/Security/PermissionChecker.php` | Voter and entry points | FR-CHK-001 |
| `src/Security/PermissionGateInterface.php` | Voter and entry points | FR-VOT-002 |
| `src/Security/PermissionVoter.php` | Voter and entry points | FR-VOT-001 |
| `src/Twig/RbacTwigExtension.php` | Voter and entry points | FR-CHK-001 |

## Coverage

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Command/CheckCommand.php` | Coverage | FR-COV-001 |
| `src/Coverage/CoverageReport.php` | Coverage | FR-COV-001 |
| `src/Coverage/PermissionCoverage.php` | Coverage | FR-COV-001 |

## Admin UI security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/EventListener/RbacAdminAccessListener.php` | Admin UI security | FR-SEC-001 |
| `src/Security/AccessGuard.php` | Admin UI security | FR-SEC-001 |
| `src/Security/AllowAllRbacAdminAccessChecker.php` | Admin UI security | FR-SEC-001 |
| `src/Security/ConfigurableRbacAdminAccessChecker.php` | Admin UI security | FR-SEC-001 |
| `src/Security/RbacAdminAccessCheckerInterface.php` | Admin UI security | FR-SEC-001 |

## Admin UI (HTTP, forms, view models, Twig)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/PermissionSetController.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-002 |
| `src/Form/PermissionSetType.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-003 |
| `src/Resources/views/admin/_cells.html.twig` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-004 |
| `src/Resources/views/admin/catalogue.html.twig` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-004 |
| `src/Resources/views/admin/form.html.twig` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-004 |
| `src/Resources/views/admin/index.html.twig` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-004 |
| `src/Routing/RbacRouteLoader.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-001 |
| `src/Routing/RbacRoutes.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-001 |
| `src/Ui/ActionForms.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-003 |
| `src/Ui/RbacPageFactory.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-004 |
| `src/Ui/RbacPages.php` | Admin UI (HTTP, forms, view models, Twig) | FR-UI-004 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoRbacKitBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoRbacKitBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoRbacKitBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoRbacKitBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoRbacKitBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoRbacKitBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoRbacKitBundle.pt.yaml` | Translations | FR-I18N-001 |

## Coverage summary

| Category | Files |
| --- | --- |
| Bundle and dependency injection | 6 |
| Catalogue | 5 |
| Permission sets | 12 |
| Grants and contexts | 10 |
| Voter and entry points | 7 |
| Coverage | 3 |
| Admin UI security | 5 |
| Admin UI (HTTP, forms, view models, Twig) | 11 |
| Translations | 7 |
| **Total production sources** | **66** |

Mapped 66 / 66 files under `src/` (100%). No generated or build output is shipped under `src/`.
