<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Command\Tools;

use In2code\In2publishCore\Service\Context\ContextService;
use In2code\In2publishCore\Service\Environment\EnvironmentService;
use In2code\In2publishCore\Testing\Service\TestingService;
use In2code\In2publishCore\Testing\Tests\TestResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use const PHP_EOL;

class TestCommand extends Command
{
    public const EXIT_TESTS_FAILED = 240;
    public const IDENTIFIER = 'in2publish_core:tools:test';

    public function isEnabled()
    {
        return GeneralUtility::makeInstance(ContextService::class)->isLocal();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $testingService = GeneralUtility::makeInstance(TestingService::class);
        try {
            $testingResults = $testingService->runAllTests();
            $success = true;

            foreach ($testingResults as $testingResult) {
                if ($testingResult->getSeverity() === TestResult::ERROR) {
                    $success = false;
                    break;
                }
            }
        } catch (Throwable $e) {
            $testingResults = [];
            $success = false;
        }

        $environmentService = GeneralUtility::makeInstance(EnvironmentService::class);
        $environmentService->setTestResult($success);

        if (true !== $success) {
            foreach ($testingResults as $testingResult) {
                if ($testingResult->getSeverity() === TestResult::ERROR) {
                    $errOutput->writeln($testingResult->getTranslatedLabel() . PHP_EOL);
                    $errOutput->writeln($testingResult->getTranslatedMessages() . PHP_EOL);
                }
            }
            return static::EXIT_TESTS_FAILED;
        }

        $output->writeln('All tests passed', OutputInterface::VERBOSITY_VERBOSE);
        return 0;
    }
}
