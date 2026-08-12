<?php

namespace Plugin\SiteMigration\Backend\Support;

use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Who may move what.
 *
 * The plugin declares its own resource, `site_migration`, and **that gate is necessary and
 * nowhere near sufficient.** An import writes products, pages and users. If it checked only
 * `site_migration.create`, then granting somebody the right to run a migration would grant them
 * the right to rewrite the entire catalogue without holding `products.update` — a
 * privilege-escalation path wearing a convenience feature's name.
 *
 * So **every resource in the selection is checked against its own permission, in both
 * directions**, which is the rule core's spreadsheet importer already enforces in
 * `ImporterRegistry::gate()`:
 *
 * | Action                      | Requires                                        |
 * |-----------------------------|-------------------------------------------------|
 * | Export resource *X*         | `site_migration.create` **and** `X.view`        |
 * | Import resource *X*, creating | `site_migration.create` **and** `X.create`    |
 * | Import resource *X*, overwriting | additionally `X.update`                    |
 *
 * **Deliberately not expressed as middleware.** `RoleOrPermissionMiddleware` passes on *any*
 * name it is given, so listing every resource on the route would grant the whole set to anyone
 * holding one of them — the exact opposite of the intent. The check is explicit and per-request,
 * and a caller who may migrate but not write products gets a refusal naming `products` rather
 * than a generic one.
 */
class Permissions
{
    /** The plugin's own resource. Not a core name — a plugin may not use one. */
    public const RESOURCE = 'site_migration';

    /**
     * Whether the current operator holds a permission.
     *
     * `super_admin` is the standing escape hatch, matching `Helpers::permissionMiddleware()` and
     * `ImporterRegistry::gate()`. Mirrored rather than reasoned about afresh: a plugin that
     * decided this differently would be a second answer to a question core has already settled.
     */
    public static function allows(string $permission): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->can($permission);
    }

    /** May the operator open these screens at all. */
    public static function mayRun(): bool
    {
        return self::allows(self::RESOURCE . '.create');
    }

    /**
     * Refuse unless the operator may run a migration at all.
     *
     * @throws UnauthorizedHttpException|AccessDeniedHttpException
     */
    public static function assertMayRun(): void
    {
        if (Auth::user() === null) {
            throw new UnauthorizedHttpException('Bearer', 'You must be signed in to run a migration.');
        }

        if (! self::allows(self::RESOURCE . '.create')) {
            throw new AccessDeniedHttpException(
                'You do not have permission to run a site migration.'
            );
        }
    }

    /**
     * The resources from a selection this operator may actually read.
     *
     * Filtered rather than refused, because an export is a *choice* of what travels and a
     * resource the operator cannot see is one they did not knowingly ask for. The screen reports
     * what was dropped, so nothing is silently missing from the bundle — an export that quietly
     * omitted products would be discovered on the destination, which is the worst place to find
     * out.
     *
     * @param  array<int,string>  $resources
     * @return array{allowed: array<int,string>, refused: array<int,string>}
     */
    public static function filterReadable(array $resources): array
    {
        $allowed = $refused = [];

        foreach ($resources as $resource) {
            if (self::allows($resource . '.view')) {
                $allowed[] = $resource;
            } else {
                $refused[] = $resource;
            }
        }

        return ['allowed' => $allowed, 'refused' => $refused];
    }

    /**
     * Refuse an import that would write a resource this operator may not write.
     *
     * **A refusal, not a filter, and the asymmetry with `filterReadable()` is the point.** An
     * export the operator narrowed is still a valid bundle. An import silently skipping a
     * resource in the bundle produces a destination that is *partly* migrated and looks
     * finished — pages pointing at categories that were never created. Better to name the
     * resource and write nothing.
     *
     * @param  array<int,string>  $resources
     *
     * @throws AccessDeniedHttpException
     */
    public static function assertMayWrite(array $resources, bool $overwriting): void
    {
        self::assertMayRun();

        foreach ($resources as $resource) {
            foreach (self::writeVerbs($resource, $overwriting) as $verb) {
                if (self::allows($resource . '.' . $verb)) {
                    continue;
                }

                throw new AccessDeniedHttpException(sprintf(
                    'You do not have permission to %s %s, so this bundle cannot be imported. '
                    . 'Either ask for that permission, or untick %s and import the rest.',
                    $verb,
                    $resource,
                    $resource
                ));
            }
        }
    }

    /**
     * The write verbs to demand for a resource, narrowed to the ones it actually declares.
     *
     * **Not a fixed `['create', 'update']`.** Core's `settings` resource — which gates email
     * templates — declares only `view` and `update`, because the six templates always exist and
     * writing one is always an update. Demanding `settings.create` would refuse every operator
     * alive, including a super admin's own admin colleagues, for a permission that is not merely
     * ungranted but *not defined anywhere*.
     *
     * A resource core does not describe at all keeps the ordinary pair, so a driver added later
     * fails closed rather than being waved through.
     *
     * @return array<int,string>
     */
    private static function writeVerbs(string $resource, bool $overwriting): array
    {
        $declared = (array) config('settings.permissions.' . $resource, []);

        $has = static fn (string $verb) => $declared === []
            || in_array($resource . '.' . $verb, $declared, true);

        $verbs = [];

        if ($has('create')) {
            $verbs[] = 'create';
        }

        if (($overwriting || $verbs === []) && $has('update')) {
            $verbs[] = 'update';
        }

        // A resource declaring neither is not writable by anyone through this package, and saying
        // so with `create` produces the clearest refusal available.
        return $verbs === [] ? ['create'] : $verbs;
    }
}
