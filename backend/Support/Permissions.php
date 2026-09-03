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
     * Resources whose import is a **code deployment**, not a data write.
     *
     * A theme is Blade and a plugin is PHP; importing either puts a third party's executable files
     * on this server, where the theme's are also web-served through the `public/themes` symlink and
     * the plugin's run with full application privileges the moment it is enabled.
     *
     * Core already decided what that costs: `PluginController::store()` refuses a plugin install to
     * anybody but a super administrator, with the comment that installing runs third-party PHP with
     * full privileges. This package reached the same directories needing only `plugins.create` —
     * which `SyncPermissions` grants the `admin` role along with every other non-`.delete`
     * permission — so a migration bundle was a way around a gate core states outright. Mirroring
     * the gate is the fix; inventing a different answer to a question core has settled would be
     * the mistake.
     *
     * @var array<int,string>
     */
    public const CODE_RESOURCES = ['themes', 'plugins'];

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

    /**
     * Whether the caller holds the standing escape hatch.
     *
     * Named rather than repeated, because it is now asked in two places and the two must not be
     * able to drift.
     */
    public static function isSuperAdmin(): bool
    {
        return (bool) Auth::user()?->hasRole('super_admin');
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
            // Checked before the ordinary verbs, because holding `themes.create` is not the
            // question being asked here. See {@see CODE_RESOURCES}.
            if (in_array($resource, self::CODE_RESOURCES, true) && ! self::isSuperAdmin()) {
                throw new AccessDeniedHttpException(sprintf(
                    'Importing %s means installing code from the site this bundle came from, which '
                    . 'only a super administrator may do — the same rule core applies to uploading '
                    . 'a plugin. Either ask a super administrator to run this import, or untick %s '
                    . 'and import the rest.',
                    $resource,
                    $resource
                ));
            }

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
