<?php

declare(strict_types=1);

namespace In2code\In2publishCore\ViewHelpers\File;

/*
 * Copyright notice
 *
 * (c) 2015 in2code.de
 * Alex Kellner <alexander.kellner@in2code.de>,
 * Oliver Eglseder <oliver.eglseder@in2code.de>
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

use In2code\In2publishCore\Config\ConfigContainer;
use In2code\In2publishCore\Domain\Driver\RemoteFileAbstractionLayerDriver;
use In2code\In2publishCore\Domain\Model\Record;
use In2code\In2publishCore\Utility\UriUtility;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

use function ltrim;
use function rtrim;

/**
 * Class BuildResourcePathViewHelper
 */
class BuildResourcePathViewHelper extends AbstractViewHelper
{
    /**
     * @var Uri[]
     */
    protected $domains;

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function initialize()
    {
        $config = GeneralUtility::makeInstance(ConfigContainer::class)->get('filePreviewDomainName');
        foreach (['local', 'foreign'] as $stagingLevel) {
            $this->domains[$stagingLevel] = UriUtility::normalizeUri(new Uri($config[$stagingLevel]));
        }
    }

    /**
     *
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument('record', Record::class, 'record of the file, which needs the ressource path', true);
        $this->registerArgument('stagingLevel', 'string', 'Sets the staging level [LOCAL/foreign]', true, 'local');
    }

    /**
     * @return string
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function render(): string
    {
        /** @var Record $record */
        $record = $this->arguments['record'];

        /** @var string $propertyName */
        $stagingLevel = $this->arguments['stagingLevel'];

        $resourceUrl = null;
        if ('sys_file' !== $record->getTableName()) {
            // TODO: maybe throw exception
            return '';
        }

        if ('local' === $stagingLevel && $record->localRecordExists()) {
            $storage = $record->getPropertyBySideIdentifier($stagingLevel, 'storage');
            $identifier = $record->getPropertyBySideIdentifier($stagingLevel, 'identifier');

            $resourceFactory = ResourceFactory::getInstance();
            /** @var File $file Keep this annotation for the correct method return type generation */
            $file = $resourceFactory->getFileObjectByStorageAndIdentifier($storage, $identifier);
            $resourceUrl = $file->getPublicUrl();
        }

        if ('foreign' === $stagingLevel && $record->foreignRecordExists()) {
            $storage = $record->getPropertyBySideIdentifier($stagingLevel, 'storage');
            $identifier = $record->getPropertyBySideIdentifier($stagingLevel, 'identifier');

            $remoteFalDriver = GeneralUtility::makeInstance(RemoteFileAbstractionLayerDriver::class);
            $remoteFalDriver->setStorageUid($storage);
            $remoteFalDriver->initialize();
            $resourceUrl = $remoteFalDriver->getPublicUrl($identifier);
        }

        if (null === $resourceUrl) {
            // TODO: maybe throw exception
            return '';
        }

        $uri = $this->domains[$stagingLevel];
        $uri = $uri->withPath(rtrim($uri->getPath(), '/') . '/' . ltrim($resourceUrl, '/'));
        return (string)$uri;
    }
}
