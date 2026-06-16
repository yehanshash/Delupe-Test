<?php

namespace App\Command;

use App\Message\ImportProductsMessage;
use App\Service\FeedReader;
use App\Service\ProductImporter;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:import-products',
    description: 'Import products from a JSON feed file (validate, insert new, update existing).',
)]
class ImportProductsCommand extends Command
{
    public function __construct(
        private readonly FeedReader $feedReader,
        private readonly ProductImporter $importer,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the JSON feed file (e.g. products.json)')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Queue the import for asynchronous processing instead of running it now.')
            ->setHelp(<<<'HELP'
                Reads products from a JSON file, validates each record and stores them in PostgreSQL.

                Existing products (matched on merchant id + product id) are updated; new ones are inserted.
                Invalid records are skipped and logged.

                  <info>php bin/console app:import-products products.json</info>
                  <info>php bin/console app:import-products products.json --async</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $file */
        $file = $input->getArgument('file');
        $path = $this->resolvePath($file);

        if (!is_file($path)) {
            $io->error(\sprintf('File not found: %s', $file));

            return Command::FAILURE;
        }

        if ($input->getOption('async')) {
            $this->bus->dispatch(new ImportProductsMessage($path));
            $io->success(\sprintf('Import for "%s" has been queued for asynchronous processing.', $path));
            $io->note('Run a worker to process it: php bin/console messenger:consume async');

            return Command::SUCCESS;
        }

        try {
            $records = $this->feedReader->read($path);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Importing products');
        $io->text(\sprintf('File: %s', $path));
        $io->text(\sprintf('Records found: %d', \count($records)));

        $result = $this->importer->import($records);

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Imported (new)', (string) $result->imported],
                ['Updated (existing)', (string) $result->updated],
                ['Failed (invalid)', (string) $result->failedCount()],
                ['Total processed', (string) $result->total()],
            ],
        );

        if ($result->failedCount() > 0) {
            $io->section('Invalid records');
            $rows = [];
            foreach ($result->failed as $failure) {
                $rows[] = [
                    (string) $failure['index'],
                    '' === $failure['external_id'] ? '(missing id)' : $failure['external_id'],
                    implode("\n", $failure['errors']),
                ];
            }
            $io->table(['Index', 'Product id', 'Errors'], $rows);
            $io->warning(\sprintf('%d record(s) were skipped. See logs for details.', $result->failedCount()));
        }

        $io->success('Import finished.');

        return Command::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        if ($this->isAbsolute($file)) {
            return $file;
        }

        return getcwd().\DIRECTORY_SEPARATOR.$file;
    }

    private function isAbsolute(string $path): bool
    {
        return '' !== $path
            && ('/' === $path[0] || '\\' === $path[0] || 1 === preg_match('#^[A-Za-z]:[\\\\/]#', $path));
    }
}
