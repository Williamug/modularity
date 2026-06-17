<?php

namespace Modularity\Marketplace;

use Modularity\Marketplace\Contracts\LicenseVerifierInterface;

class NullLicenseVerifier implements LicenseVerifierInterface
{
    public function verify(string $slug, int $tenantId, ?string $licenseKey = null): bool
    {
        return true;
    }
}
