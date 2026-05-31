<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Command;

use Ppl\PplDeeplV3BatchTranslation\Service\BatchExecutionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'ppl:batch-translation:execute-job',
    description: 'Execute a prepared preview job from CLI for larger batches.'
)]
final class ExecuteJobCommand extends Command
{
    public function __construct(
        private readonly BatchExecutionService $executionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Preview job UID to execute.')
            ->addOption('be-user', null, InputOption::VALUE_REQUIRED, 'Backend user UID used for permission checks.')
            ->addOption('hidden', null, InputOption::VALUE_NONE, 'Keep written target records hidden.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required safety confirmation for CLI writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobUid = max(0, (int)$input->getOption('job'));
        $backendUserUid = max(0, (int)$input->getOption('be-user'));
        if ($jobUid <= 0 || $backendUserUid <= 0 || !(bool)$input->getOption('force')) {
            $output->writeln('<error>Provide --job, --be-user and --force to execute a preview job.</error>');
            return Command::INVALID;
        }

        /** @var BackendUserAuthentication $backendUser */
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($backendUserUid);
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;

        $result = $this->executionService->executePreviewJob($jobUid, !(bool)$input->getOption('hidden'));
        $output->writeln(sprintf('<%s>%s</%s>', $result['type'] === 'error' ? 'error' : 'info', $result['text'], $result['type'] === 'error' ? 'error' : 'info'));

        return $result['type'] === 'error' ? Command::FAILURE : Command::SUCCESS;
    }
}
