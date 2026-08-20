<?php

declare(strict_types=1);

namespace OptimisticConcurrency\Bundle\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Stable representation context passed to custom entity-tag providers.
 *
 * The request exposes representation inputs such as locale, route and query
 * parameters. The optional scope is an explicit application-defined
 * representation name and is preferable to coupling validator logic to route
 * names when the same representation is exposed from more than one endpoint.
 */
final readonly class EntityTagContext
{
    public Request $request;
    public ?string $scope;

    public function __construct(Request $request, ?string $scope = null)
    {
        if (null !== $scope) {
            $scope = trim($scope);

            if ('' === $scope) {
                throw new \InvalidArgumentException('The entity-tag representation scope must be null or a non-empty string.');
            }
        }

        $this->request = $request;
        $this->scope = $scope;
    }
}
