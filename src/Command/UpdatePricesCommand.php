<?php

namespace App\Command;

use App\Service\PriceAdjuster;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-prices',
    description: 'Adjust all product prices by a percentage, preserving the original price.',
)]
class UpdatePricesCommand extends Command
{
    public function __construct(
        private readonly PriceAdjuster $priceAdjuster,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('percentage', InputArgument::REQUIRED, 'Percentage change, e.g. 10 (+10%) or -5 (-5%)')
            ->setHelp(<<<'HELP'
                Increases or decreases every product price by the given percentage.

                Before adjusting, the pre-adjustment price is stored in "original_price"
                (only when it is not already set, so the true original is never lost).

                  <info>php bin/console app:update-prices 10</info>     # +10%
                  <info>php bin/console app:update-prices -5</info>     # -5%
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $raw */
        $raw = $input->getArgument('percentage');

        if (!is_numeric($raw)) {
            $io->error(\sprintf('Percentage must be a number, got "%s".', $raw));

            return Command::INVALID;
        }

        try {
            $result = $this->priceAdjuster->adjust((float) $raw);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if (0 === $result['affected']) {
            $io->warning('No products found — nothing to adjust.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Adjusted %d product price(s) by %+.2f%% (factor %.4f).',
            $result['affected'],
            $result['percentage'],
            $result['factor'],
        ));

        return Command::SUCCESS;
    }
}
