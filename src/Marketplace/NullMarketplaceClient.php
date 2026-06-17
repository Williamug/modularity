<?php

namespace Modularity\Marketplace;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modularity\Marketplace\Contracts\MarketplaceClientInterface;

class NullMarketplaceClient implements MarketplaceClientInterface
{
    public function browse(array $filters = []): Collection
    {
        $this->warnIfConfigured();

        return collect();
    }

    public function getModule(string $slug): ?object
    {
        $this->warnIfConfigured();

        return null;
    }

    public function download(string $slug, string $version): string
    {
        $this->warnIfConfigured();

        throw new \RuntimeException(
            "Marketplace client is not configured. Set MODULARITY_MARKETPLACE_URL to enable marketplace integration (Phase 2)."
        );
    }

    private function warnIfConfigured(): void
    {
        if (config('modularity.marketplace.api_url')) {
            Log::warning('[Modularity] MODULARITY_MARKETPLACE_URL is set but the NullMarketplaceClient is active. Swap the binding in config/modularity.php to enable Phase 2 integration.');
        }
    }
}
