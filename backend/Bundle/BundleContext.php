<?php

namespace Plugin\SiteMigration\Backend\Bundle;

/**
 * Where a driver may read and write files, and what the bundle says about its source.
 *
 * Almost every driver ignores this: a tag is entirely described by its columns. The asset driver
 * does not, because its record is a *pointer* to bytes, and the bytes live beside the data files
 * rather than inside them.
 *
 * Passed to drivers rather than injected, because the answer changes per run — two imports being
 * resumed in the same process would otherwise share one directory.
 */
final class BundleContext
{
    public function __construct(
        /** Where the bundle's own files live: staging on export, extracted on import. */
        public readonly string $path,
        /** Whether this run carries media bytes as well as rows. */
        public readonly bool $includeMedia,
        /** The install the bundle came from, so URLs pointing at it can be recognised. */
        public readonly string $sourceUrl = '',
    ) {
    }

    /** Where one source asset's bytes sit inside the bundle. */
    public function mediaDirectory(int $sourceId): string
    {
        return $this->path . '/media/' . $sourceId;
    }
}
