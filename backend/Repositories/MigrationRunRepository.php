<?php

namespace Plugin\SiteMigration\Backend\Repositories;

use Plugin\SiteMigration\Backend\Pages\ExportPage;
use Plugin\SiteMigration\Backend\Pages\HistoryPage;
use Plugin\SiteMigration\Backend\Pages\ImportPage;

/**
 * The module Ovynt resolves for `migration-runs`.
 *
 * **The class name is derived, not chosen.** `GenericModuleController` takes the module type,
 * singularises and studly-cases it — `migration-runs` → `migration-run` → `MigrationRun` — and
 * looks for `MigrationRunRepository`. Get it wrong and every screen 404s with `Unknown module` in
 * the log. It is also the one place the package's own naming rule (the word *Migration* never
 * appears in a class name, so the two senses of the word cannot be confused) has to give way, and
 * it gives way because the engine decides this one, not the author.
 *
 * There is no base class and no interface — Ovynt resolves this by name and calls the methods
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
     * No list screen, and therefore nothing to paginate.
     *
     * `module.json` declares no `table`, so the engine never asks — but if it ever did, the
     * contract says a **query builder**, never a collection. There is no model here to build one
     * from, so this returns null and the caller's `DataTables::eloquent()` fails loudly rather
     * than this method inventing an empty table that would read as "no runs" forever.
     */
    public function baseIndexQuery(array $filters = [])
    {
        return null;
    }

    public function create(array $data)
    {
        return null;
    }

    public function find($id)
    {
        return null;
    }

    public function update($id, array $data)
    {
        return null;
    }

    public function delete($id)
    {
        return null;
    }

    public function getOptions(array $columns = [])
    {
        return [];
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
            'export'         => app(ExportPage::class)->save($data),
            'import'         => app(ImportPage::class)->inspect($data),
            'import-preview' => app(ImportPage::class)->preview($data),
            'import-apply'   => app(ImportPage::class)->apply($data),
            default          => null,
        };
    }
}
