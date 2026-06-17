<?php

namespace Modularity\Core\Module;

use Modularity\Core\Module\Exceptions\InvalidManifestException;

class ModuleManifest
{
    private const REQUIRED_FIELDS = ['name', 'slug', 'version', 'providers'];

    public static function parse(string $modulePath): ManifestDTO
    {
        $manifestPath = rtrim($modulePath, '/').'/module.json';

        $contents = file_get_contents($manifestPath);

        if ($contents === false) {
            throw InvalidManifestException::invalidJson($manifestPath);
        }

        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw InvalidManifestException::invalidJson($manifestPath);
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($data[$field])) {
                throw InvalidManifestException::missingField($field, $manifestPath);
            }
        }

        $slug = $data['slug'];

        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw InvalidManifestException::invalidSlug($slug, $manifestPath);
        }

        return new ManifestDTO(
            name:          $data['name'],
            slug:          $slug,
            version:       $data['version'],
            description:   $data['description'] ?? '',
            providers:     (array) $data['providers'],
            permissions:   (array) ($data['permissions'] ?? []),
            dependencies:  (array) ($data['dependencies'] ?? []),
            compatibility: $data['compatibility'] ?? '*',
            path:          rtrim($modulePath, '/'),
        );
    }
}
