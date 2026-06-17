<?php

namespace Modularity\Marketplace\Contracts;

interface LicenseVerifierInterface
{
    /**
     * Verify that the tenant holds a valid license for the module.
     */
    public function verify(string $slug, int $tenantId, ?string $licenseKey = null): bool;
}
