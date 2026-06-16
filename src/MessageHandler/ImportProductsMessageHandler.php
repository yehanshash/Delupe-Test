<?php

namespace App\MessageHandler;

use App\Message\ImportProductsMessage;
use App\Service\FeedReader;
use App\Service\ProductImporter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportProductsMessageHandler
{
    public function __construct(
        private readonly FeedReader $feedReader,
        private readonly ProductImporter $importer,
        private readonly LoggerInterface $importLogger,
    ) {
    }

    public function __invoke(ImportProductsMessage $message): void
    {
        $path = $message->getFilePath();
        $this->importLogger->info('Processing queued import', ['file' => $path]);

        $records = $this->feedReader->read($path);
        $result = $this->importer->import($records);

        $this->importLogger->info('Queued import finished', $result->toArray() + ['file' => $path]);
    }
}
