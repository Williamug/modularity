<?php

namespace Modularity\Marketplace\Contracts;

use Illuminate\Support\Collection;

interface MarketplaceClientInterface
{
    /**
     * Browse the marketplace catalog with optional filters.
     *
     * @param array $filters  e.g. ['category' => 'accounting', 'search' => 'invoice']
     */
    public function browse(array $filters = []): Collection;

    /**
     * Get metadata for a specific module from the marketplace.
     */
    public function getModule(string $slug): ?object;

    /**
     * Download a module package and return the local path to the extracted files.
     *
     * @throws \RuntimeException if download fails or signature verification fails
     */
    public function download(string $slug, string $version): string;
}
