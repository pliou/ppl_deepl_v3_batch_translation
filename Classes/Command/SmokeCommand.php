<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3BatchTranslation\Command;

use Ppl\PplDeeplV3BatchTranslation\Service\Smoke\SmokeContext;
use Ppl\PplDeeplV3BatchTranslation\Service\Smoke\SmokeFixtureService;
use Ppl\PplDeeplV3BatchTranslation\Service\Smoke\SmokeMatrixRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

#[AsCommand(
    name: 'ppl:batch-translation:smoke',
    description: 'Create and run the PPL DeepL V3 batch translation smoke fixture and matrix.'
)]
final class SmokeCommand extends Command
{
    public function __construct(
        private readonly SmokeContext $context,
        private readonly SmokeFixtureService $fixtureService,
        private readonly SmokeMatrixRunner $matrixRunner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reset-fixture', null, InputOption::VALUE_NONE, 'Recreate the deterministic smoke fixture.')
            ->addOption('run-matrix', null, InputOption::VALUE_NONE, 'Run the smoke matrix after preparing the fixture.')
            ->addOption('case', null, InputOption::VALUE_REQUIRED, 'Run only one case id, for example BT-SMOKE-002.')
            ->addOption('keep-fixture', null, InputOption::VALUE_NONE, 'Reuse fixture-uids.json from the artifact root when present.')
            ->addOption('keep-fake-active', null, InputOption::VALUE_NONE, 'Keep Fake DeepL active after fixture preparation. Development/Testing context only.')
            ->addOption('artifact-root', null, InputOption::VALUE_REQUIRED, 'Artifact root path. Defaults to var/smoke/batch-translation/<timestamp>.')
            ->addOption('deactivate-fake', null, InputOption::VALUE_NONE, 'Deactivate the persistent Fake DeepL smoke context and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->canRunInCurrentContext()) {
            $output->writeln('<error>Batch translation smoke fixtures are only allowed in TYPO3 Development or Testing context.</error>');

            return Command::FAILURE;
        }

        if ((bool)$input->getOption('deactivate-fake')) {
            $this->context->deactivate();
            $output->writeln('<info>Smoke Fake DeepL context deactivated.</info>');

            return Command::SUCCESS;
        }

        $artifactRoot = trim((string)($input->getOption('artifact-root') ?? ''));
        if ($artifactRoot === '') {
            $artifactRoot = Environment::getVarPath() . '/smoke/batch-translation/' . date('Ymd-His');
        }
        if (!str_starts_with($artifactRoot, '/')) {
            $artifactRoot = Environment::getProjectPath() . '/' . $artifactRoot;
        }
        if (!is_dir($artifactRoot)) {
            mkdir($artifactRoot, 0775, true);
        }

        $fixturePath = $artifactRoot . '/fixture-uids.json';
        $fixture = [];
        if ((bool)$input->getOption('keep-fixture') && is_file($fixturePath)) {
            $fixture = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
            $output->writeln('<info>Reusing smoke fixture from fixture-uids.json.</info>');
        } else {
            $fixture = $this->fixtureService->resetAndCreate($artifactRoot);
            $output->writeln('<info>Smoke fixture created.</info>');
        }

        $this->context->activate($artifactRoot);
        $output->writeln('<info>Fake DeepL smoke context active.</info>');
        $output->writeln('Artifact root: ' . $artifactRoot);

        if ((bool)$input->getOption('run-matrix')) {
            try {
                $summary = $this->matrixRunner->runMatrix($fixture, $artifactRoot, $input->getOption('case') ? (string)$input->getOption('case') : null);
                $failed = array_filter($summary['cases'], static fn(array $case): bool => $case['status'] !== 'PASS');
                $output->writeln(sprintf(
                    '<info>Smoke matrix finished: %d passed, %d failed.</info>',
                    count($summary['cases']) - count($failed),
                    count($failed)
                ));
                $output->writeln('Summary: ' . $artifactRoot . '/summary.md');

                return $failed === [] ? Command::SUCCESS : Command::FAILURE;
            } finally {
                $this->context->deactivate();
                $output->writeln('<info>Smoke Fake DeepL context deactivated.</info>');
            }
        }

        if (!(bool)$input->getOption('keep-fake-active')) {
            $this->context->deactivate();
            $output->writeln('<info>Smoke Fake DeepL context deactivated.</info>');
        }

        $output->writeln('<comment>Fixture prepared. Pass --run-matrix to execute smoke cases.</comment>');

        return Command::SUCCESS;
    }

    private function canRunInCurrentContext(): bool
    {
        $context = Environment::getContext();

        return $context->isDevelopment() || $context->isTesting();
    }
}
