<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Command\Status;

/*
 * Copyright notice
 *
 * (c) 2019 in2code.de and the following authors:
 * Oliver Eglseder <oliver.eglseder@in2code.de>
 *
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 */

use In2code\In2publishCore\Testing\Tests\Application\ForeignDatabaseConfigTest;
use In2code\In2publishCore\Utility\DatabaseUtility;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function base64_encode;
use function json_encode;

class DbConfigTestCommand extends Command
{
    public const IDENTIFIER = 'in2publish_core:status:dbconfigtest';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queryBuilder = DatabaseUtility::buildLocalDatabaseConnection()->createQueryBuilder();
        $predicates = $queryBuilder->expr()->eq(
            'task_type',
            $queryBuilder->createNamedParameter(ForeignDatabaseConfigTest::DB_CONFIG_TEST_TYPE)
        );
        $queryBuilder->select('*')->from('tx_in2code_in2publish_task')->where($predicates);
        $result = $queryBuilder->execute()->fetchAll();
        $output->writeln('DB Config: ' . base64_encode(json_encode(array_column($result, 'configuration'))));
        return 0;
    }
}
