<?php

namespace App\Service;

use JsonException;
use RuntimeException;

/**
 * Reads and decodes a product feed JSON file into a list of raw records.
 *
 * Supports either a top-level JSON array or an object with a "products" key.
 */
class FeedReader
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when the file is missing or not valid JSON
     */
    public function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(\sprintf('File "%s" does not exist or is not readable.', $path));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new RuntimeException(\sprintf('Unable to read file "%s".', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(\sprintf('Invalid JSON in "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        if (isset($decoded['products']) && \is_array($decoded['products'])) {
            $decoded = $decoded['products'];
        }

        if (!\is_array($decoded) || false === array_is_list($decoded)) {
            // Allow a single object too.
            if (\is_array($decoded) && [] !== $decoded) {
                return [$decoded];
            }

            throw new RuntimeException('Feed must be a JSON array of product records.');
        }

        /* @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
