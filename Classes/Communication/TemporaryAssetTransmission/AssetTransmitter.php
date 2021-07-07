<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Communication\TemporaryAssetTransmission;

/*
 * Copyright notice
 *
 * (c) 2017 in2code.de and the following authors:
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

use In2code\In2publishCore\Communication\AdapterRegistry;
use In2code\In2publishCore\Communication\TemporaryAssetTransmission\Exception\FileMissingException;
use In2code\In2publishCore\Communication\TemporaryAssetTransmission\TransmissionAdapter\AdapterInterface;
use In2code\In2publishCore\Config\ConfigContainer;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Throwable;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function file_exists;
use function rtrim;
use function uniqid;

/**
 * Class AssetTransmitter
 */
class AssetTransmitter implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var AdapterInterface */
    protected $adapter;

    /** @var AdapterRegistry */
    protected $adapterRegistry;

    /** @var string */
    protected $foreignRootPath;

    public function __construct(ConfigContainer $configContainer, AdapterRegistry $adapterRegistry)
    {
        $this->foreignRootPath = rtrim($configContainer->get('foreign.rootPath'), '/');
        $this->adapterRegistry = $adapterRegistry;
    }

    /**
     * @param string $source Absolute local path to file(return value of
     *     \TYPO3\CMS\Core\Resource\Driver\DriverInterface::getFileForLocalProcessing)
     *
     * @return string Absolute path of the transmitted file on foreign
     *
     * @throws FileMissingException
     */
    public function transmitTemporaryFile(string $source): string
    {
        $this->logger->info('Transmission of file requested', ['source' => $source]);

        if (!file_exists($source)) {
            $this->logger->error('File does not exist', ['source' => $source]);
            throw FileMissingException::fromFileName($source);
        }

        if (null === $this->adapter) {
            try {
                $adapterClass = $this->adapterRegistry->getAdapter(AdapterInterface::class);
                $this->adapter = GeneralUtility::makeInstance($adapterClass);
            } catch (Throwable $exception) {
                $this->logger->debug('SshAdapter initialization failed. See previous log for reason.');
            }
        }

        $target = $this->foreignRootPath . '/typo3temp/' . uniqid('tx_in2publishcore_temp_');

        $success = $this->adapter->copyFileToRemote($source, $target);

        if (true === $success) {
            $this->logger->debug('Successfully transferred file to foreign', ['target' => $target]);
        } else {
            $this->logger->error('Failed to transfer file to foreign', ['target' => $target]);
        }

        return $target;
    }
}
