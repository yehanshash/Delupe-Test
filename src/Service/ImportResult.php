<?php

namespace App\Service;

/**
 * Mutable summary collected while importing a product feed.
 */
final class ImportResult
{
    public int $imported = 0;
    public int $updated = 0;

    /**
     * @var list<array{index: int, external_id: string, errors: list<string>}>
     */
    public array $failed = [];

    public function addImported(): void
    {
        ++$this->imported;
    }

    public function addUpdated(): void
    {
        ++$this->updated;
    }

    /**
     * @param list<string> $errors
     */
    public function addFailure(int $index, string $externalId, array $errors): void
    {
        $this->failed[] = [
            'index' => $index,
            'external_id' => $externalId,
            'errors' => $errors,
        ];
    }

    public function failedCount(): int
    {
        return \count($this->failed);
    }

    public function total(): int
    {
        return $this->imported + $this->updated + $this->failedCount();
    }

    /**
     * @return array{imported: int, updated: int, failed: int, total: int}
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'updated' => $this->updated,
            'failed' => $this->failedCount(),
            'total' => $this->total(),
        ];
    }
}
