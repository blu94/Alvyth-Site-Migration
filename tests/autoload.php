<?php

/*
|--------------------------------------------------------------------------
| Make this package's classes loadable from its own tests
|--------------------------------------------------------------------------
| Alvyth resolves `Plugin\SiteMigration\…` through a runtime autoloader in
| `PluginServiceProvider`, keyed on the installed set read from the `plugins`
| table — one of the three independent gates that stop a disabled plugin from
| running. It is a runtime autoloader rather than a composer PSR-4 entry
| because the set of paths is not known until the database says which plugins
| are installed.
|
| The suite empties that table before the first test (`tests/bootstrap.php`),
| so no amount of reinstalling puts a row in front of these tests: by the time
| one of them asks for `Run` there is nothing to resolve it, and every test
| errors with `Class … not found`. The dependency is removed here rather than
| worked around outside.
|
| Mirrors the provider's mapping exactly, including lowercasing the first
| segment (`Backend` → `backend`) to match the folder casing on disk.
| `require_once` from each test file makes this run once per process;
| `spl_autoload_register` is global, and stacking duplicate closures would make
| every unresolved class in the suite walk them all.
*/

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugin\\SiteMigration\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $segments = explode('\\', substr($class, strlen($prefix)));

    $segments[0] = strtolower($segments[0]);

    $file = dirname(__DIR__) . '/' . implode('/', $segments) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
