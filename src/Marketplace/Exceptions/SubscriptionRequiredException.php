<?php

namespace Modularity\Marketplace\Exceptions;

use RuntimeException;

class SubscriptionRequiredException extends RuntimeException
{
    public static function forModule(string $slug, int $tenantId): self
    {
        return new self(
            "Tenant [{$tenantId}] does not have a valid subscription for module [{$slug}]."
        );
    }
}
