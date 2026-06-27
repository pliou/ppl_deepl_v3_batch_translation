<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Command;

use Ppl\PplDeeplV3BatchTranslation\Service\BatchJobLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'ppl:batch-translation:cleanup-jobs',
    description: 'Dry-run or delete old finished, discarded and failed batch translation jobs.'
)]
final class CleanupJobsCommand extends Command
{
    public function __construct(
        private readonly BatchJobLogger $jobLogger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Only clean jobs older than this many days.', 30)
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually mark matching jobs/items deleted. Omit for dry-run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int)$input->getOption('days'));
        $dryRun = !(bool)$input->getOption('execute');
        $cutoff = time() - ($days * 86400);
        $result = $this->jobLogger->cleanupFinishedJobs($cutoff, $dryRun);
        $output->writeln(sprintf(
            '%s: %d jobs and %d job items older than %d days.',
            $dryRun ? 'Dry-run' : 'Cleaned',
            $result['jobs'],
            $result['items'],
            $days
        ));

        return Command::SUCCESS;
    }
}
