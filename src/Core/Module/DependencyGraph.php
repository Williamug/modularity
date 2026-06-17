<?php

namespace Modularity\Core\Module;

use Modularity\Core\Module\Exceptions\CircularDependencyException;

class DependencyGraph
{
    /** @param ManifestDTO[] $manifests */
    public function __construct(private readonly array $manifests) {}

    /**
     * Returns manifests in topologically sorted order (dependencies before dependents).
     *
     * @return ManifestDTO[]
     * @throws CircularDependencyException
     */
    public function resolve(): array
    {
        $slugs = array_map(fn (ManifestDTO $m) => $m->slug, $this->manifests);
        $bySlug = array_combine($slugs, $this->manifests);

        // Build in-degree map and adjacency list (dep → dependents)
        $inDegree = array_fill_keys($slugs, 0);
        $adjacency = array_fill_keys($slugs, []);

        foreach ($this->manifests as $manifest) {
            foreach ($manifest->dependencySlugs() as $dep) {
                if (! isset($bySlug[$dep])) {
                    // Missing dep — not in current installed set, skip graph edge
                    continue;
                }

                $adjacency[$dep][] = $manifest->slug;
                $inDegree[$manifest->slug]++;
            }
        }

        // Kahn's algorithm
        $queue = [];

        foreach ($inDegree as $slug => $degree) {
            if ($degree === 0) {
                $queue[] = $slug;
            }
        }

        $sorted = [];

        while (! empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $bySlug[$current];

            foreach ($adjacency[$current] as $dependent) {
                $inDegree[$dependent]--;

                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($sorted) !== count($this->manifests)) {
            $remaining = array_keys(array_filter($inDegree, fn ($d) => $d > 0));
            throw CircularDependencyException::cycle($remaining);
        }

        return $sorted;
    }
}
