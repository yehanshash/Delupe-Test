<?php

namespace App\Message;

/**
 * Queued request to import a product feed from a file path.
 */
final class ImportProductsMessage
{
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}
