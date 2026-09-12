<?php

namespace Plugin\SiteMigration\Backend\Repositories;

use App\Models\Meta;
use Plugin\SiteMigration\Backend\Pages\ExportPage;
use Plugin\SiteMigration\Backend\Pages\HistoryPage;
use Plugin\SiteMigration\Backend\Pages\ImportPage;
use Plugin\SiteMigration\Backend\Resources\DriverRegistry;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * The module Alvyth resolves for `migration-runs`.
 *
 * **The class name is derived, not chosen.** `GenericModuleController` takes the module type,
 * singularises and studly-cases it — `migration-runs` → `migration-run` → `MigrationRun` — and
 * looks for `MigrationRunRepository`. Get it wrong and every screen 404s with `Unknown module` in
 * the log. It is also the one place the package's own naming rule (the word *Migration* never
 * appears in a class name, so the two senses of the word cannot be confused) has to give way, and
 * it gives way because the engine decides this one, not the author.
 *
 * There is no base class and no interface — Alvyth resolves this by name and calls the methods
 * below.
 *
 * The CRUD half is deliberately inert. This module ships no `table` and no `form`: it exists to
 * hang three custom pages off, and a run is created by pressing Export or Import, never by a
 * create form. The methods are still here because the engine may call them, and a missing method
 * is a 500 where an empty answer is a screen that simply shows nothing.
 */
class MigrationRunRepository
{
    /**
     * No list screen, and therefore nothing to list — but a real query builder all the same.
     *
     * **`GET /admin/modules/migration-runs` is routed whether or not a module declares a `table`.**
     * This returned `null` on the reasoning that failing loudly beats inventing an empty table, and
     * the reasoning was sound about tables and wrong about the failure: `DataTables::eloquent(null)`
     * is a `TypeError`, so the endpoint answered an unhandled 500 rather than a message, and the
     * repository contract — which says this **must** return a query builder — was simply not kept.
     *
     * A query that matches nothing keeps both properties. The screen the engine would render is
     * empty, which is true: runs are directories, not rows, and the three custom pages are where
     * they are managed.
     */
    public function baseIndexQuery(array $filters = [])
    {
        return Meta::query()->whereRaw('1 = 0');
    }

    /**
     * A run is created by pressing Export or Import, never by a create form.
     *
     * `405` rather than `null`: the write routes exist because the engine routes them for every
     * module, and returning `null` into them produced a success-shaped response for something that
     * had not happened. Naming the two buttons is the part an operator can act on.
     */
    public function create(array $data)
    {
        return $this->refuseWrite();
    }

    public function find($id)
    {
        return null;
    }

    public function update($id, array $data)
    {
        return $this->refuseWrite();
    }

    /**
     * Removing a run happens on the History screen, which also says what it does and does not undo.
     */
    public function delete($id)
    {
        return $this->refuseWrite();
    }

    /**
     * @throws MethodNotAllowedHttpException
     */
    private function refuseWrite(): never
    {
        throw new MethodNotAllowedHttpException(
            ['GET'],
            'A migration run is created by pressing Export or Import on this module\'s own screens, '
            . 'and removed on its History screen. There is no create or edit form for one.'
        );
    }

    /**
     * The option lists the three screens' dropdowns are built from.
     *
     * **This endpoint exists because a schema cannot hand a field its options from page data.**
     * `BuilderField::resolvedOptions()` reads `element.options` and nothing else — its `master` key
     * names a key *inside that same object*, not a key of the bound model — so
     * `{"master": "module_options", "module_options": []}` resolves to the literal empty array,
     * every time. The pickers rendered their pre-selected chips (those come from the *value*) over
     * a dropdown with nothing in it, which is why the fault survived a screen-by-screen check: the
     * page looked right until somebody opened the list.
     *
     * The only other way in is a URL, and `GET /admin/modules/{module}/options` is the one the
     * engine already routes here. Sourcing from the registry rather than restating the resource
     * list in three JSON files also keeps a single source of truth — a driver added later appears
     * in all three dropdowns with no schema edit.
     *
     * @return array<string,mixed>
     */
    public function getOptions(array $columns = [])
    {
        $registry = app(DriverRegistry::class);

        // **Only what was asked for.** The contract says the response is keyed by column and that a
        // repository should answer the columns it knows and ignore the rest; this answered all four
        // every time, and one of them - `runs` - globs the run directory and reads a `state.json`
        // per run. So opening the export screen paid for the History screen's answer.
        //
        // An empty `$columns` still means "everything": the engine sends none when no field
        // declares a `master`, and a screen that asked for nothing should not get nothing.
        $wanted = static fn (string $column) => $columns === [] || in_array($column, $columns, true);

        $out = [];

        if ($wanted('all')) {
            // `all` is what the export screen offers, because its picker asks what to *leave out*
            // and anything movable can be left out.
            $out['all'] = $registry->options($registry->keys());
        }

        if ($wanted('content')) {
            $out['content'] = $registry->options($registry->contentKeys());
        }

        if ($wanted('records')) {
            $out['records'] = $registry->options($registry->recordKeys());
        }

        if ($wanted('code')) {
            $out['code'] = $registry->options($registry->codeKeys());
        }

        if ($wanted('runs')) {
            $out['runs'] = app(HistoryPage::class)->deleteOptions();
        }

        return $out;
    }

    /**
     * Data for a custom page. The slug matches the top-level key in `module.json`.
     *
     * @return array<string,mixed>
     */
    public function pageData(string $slug)
    {
        return match ($slug) {
            'export'  => app(ExportPage::class)->data(),
            'import'  => app(ImportPage::class)->data(),
            'history' => app(HistoryPage::class)->data(),
            default   => [],
        };
    }

    /**
     * Handle a button press.
     *
     * **The import's three verbs are three slugs, and that is forced by the engine.** A custom
     * page's button posts the *whole bound model* to whatever `endpoint` it declares — the
     * `fields`/`params` mechanism belongs to `ModuleWrapper`'s `request` action, which custom
     * pages do not use — so there is no way for a button to add `action: "preview"` to the body.
     * Distinct endpoints are the one thing a button *can* vary, and `savePageData` is handed the
     * slug, so the verb travels in the URL.
     *
     * `import-preview` and `import-apply` are deliberately **not** declared in `module.json`.
     * They are write endpoints, not screens; declaring them would put two more empty pages in the
     * router that nothing links to and `pageData()` would answer for.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null
     */
    public function savePageData(string $slug, array $data)
    {
        return match ($slug) {
            'export'          => app(ExportPage::class)->save($data),
            'export-download' => app(ExportPage::class)->publish($data),
            'import'         => app(ImportPage::class)->inspect($data),
            'import-preview' => app(ImportPage::class)->preview($data),
            'import-apply'   => app(ImportPage::class)->apply($data),
            'history-delete' => app(HistoryPage::class)->delete($data),
            default          => null,
        };
    }
}
