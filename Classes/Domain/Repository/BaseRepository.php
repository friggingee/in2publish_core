<?php

declare(strict_types=1);

namespace In2code\In2publishCore\Domain\Repository;

/*
 * Copyright notice
 *
 * (c) 2015 in2code.de and the following authors:
 * Alex Kellner <alexander.kellner@in2code.de>,
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

use In2code\In2publishCore\Config\ConfigContainer;
use In2code\In2publishCore\Domain\Model\Record;
use In2code\In2publishCore\Service\Configuration\TcaService;
use In2code\In2publishCore\Service\Database\SimpleWhereClauseParsingService;
use In2code\In2publishCore\Utility\DatabaseUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

use function array_column;
use function array_key_exists;
use function array_merge;
use function array_slice;
use function explode;
use function implode;
use function is_array;
use function json_encode;
use function preg_match;
use function spl_object_id;
use function sprintf;
use function stripos;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trigger_error;
use function trim;

use const E_USER_DEPRECATED;

/**
 * Class BaseRepository. Inherit from this repository to execute methods
 * on a specific database connection. this repository does not
 * own a database connection.
 */
abstract class BaseRepository implements SingletonInterface
{
    public const ADDITIONAL_ORDER_BY_PATTERN = '/(?P<where>.*)ORDER[\s\n]+BY[\s\n]+(?P<col>\w+(\.\w+)?)(?P<dir>\s(DESC|ASC))?/is';
    public const DEPRECATION_TABLE_NAME_FIELD = 'The field BaseRepository::$tableName is deprecated and will be removed in in2publish_core version 10. Please use the methods tableName argument instead. Method: %s';
    public const DEPRECATION_METHOD = 'The method %s is deprecated and will be removed in in2publish_core version 10.';
    public const DEPRECATION_PARAMETER = 'The parameter %s of method %s is deprecated and will be removed in in2publish_core version 10.';

    /**
     * The table name to use for any SELECT, INSERT, UPDATE and DELETE query
     *
     * @var string
     *
     * @deprecated This property is deprecated and will be removed in in2publish_core version 10.
     *  Use the available method arguments instead.
     */
    protected $tableName = '';

    /**
     * @var string
     *
     * @deprecated This property is deprecated and will be removed in in2publish_core version 10.
     *  Use the available method arguments instead.
     */
    protected $identifierFieldName = 'uid';

    /**
     * @var Logger
     */
    protected $logger = null;

    /**
     * @var TcaService
     */
    protected $tcaService = null;

    /**
     * @var ConfigContainer
     */
    protected $configContainer = null;

    /**
     * @var array<string, string>
     */
    protected $preloadTables = [];

    /**
     * @var array<string, array<string, array>>
     */
    protected $preloadCache = [];

    /**
     * @var array<string, string>
     */
    protected $statistics = [];

    /**
     * @var SimpleWhereClauseParsingService
     */
    protected $parser = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(static::class);
        $this->tcaService = GeneralUtility::makeInstance(TcaService::class);
        $this->configContainer = GeneralUtility::makeInstance(ConfigContainer::class);
        $preloadTables = $this->configContainer->get('factory.preload');
        $this->preloadTables = array_combine($preloadTables, $preloadTables);
        $this->parser = GeneralUtility::makeInstance(SimpleWhereClauseParsingService::class);
    }

    /**
     * Fetches an array of property arrays (plural !!!) from
     * the given database connection where the column
     * "$propertyName" equals $propertyValue
     *
     * @param Connection $connection
     * @param string $propertyName
     * @param mixed $propertyValue
     * @param string $additionalWhere
     * @param string $groupBy
     * @param string $orderBy
     * @param string $limit
     * @param string $indexField
     * @param string|null $tableName
     *
     * @return array
     */
    protected function findPropertiesByProperty(
        Connection $connection,
        $propertyName,
        $propertyValue,
        $additionalWhere = '',
        $groupBy = '',
        $orderBy = '',
        $limit = '',
        $indexField = 'uid',
        string $tableName = null
    ): array {
        $propertyArray = [];

        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }

        if (empty($tableName)) {
            return $propertyArray;
        }

        if (1 === preg_match(self::ADDITIONAL_ORDER_BY_PATTERN, $additionalWhere, $matches)) {
            $additionalWhere = $matches['where'];
            $orderBy = $matches['col'] . strtoupper($matches['dir'] ?? ' ASC');
        }
        $sortingField = $this->tcaService->getSortingField($tableName);
        if (empty($orderBy) && !empty($sortingField)) {
            $orderBy = $sortingField . ' ASC';
        }

        if (isset($this->preloadTables[$tableName]) && empty($groupBy)) {
            $properties = $this->parser->parseToPropertyArray($additionalWhere, $tableName);
            if (null !== $properties) {
                $properties[$propertyName] = strtolower((string)$propertyValue);
                return $this->getPreloadedRowsMatchingProperties(
                    $connection,
                    $tableName,
                    $properties,
                    $indexField,
                    $orderBy,
                    (int)$limit
                );
            }
        }
        $additionalWhere = trim($additionalWhere);
        if (0 === stripos($additionalWhere, 'and')) {
            $additionalWhere = trim(substr($additionalWhere, 3));
        }

        $query = $connection->createQueryBuilder();

        if (is_array($propertyValue)) {
            foreach ($propertyValue as $idx => $value) {
                $propertyValue[$idx] = $query->getConnection()->quote($value);
            }
            $constraint = $query->expr()->in($propertyName, $propertyValue);
        } elseif (is_int($propertyValue) || MathUtility::canBeInterpretedAsInteger($propertyValue)) {
            $constraint = $query->expr()->eq($propertyName, $query->createNamedParameter($propertyValue));
        } else {
            $constraint = $query->expr()->like($propertyName, $query->createNamedParameter($propertyValue));
        }

        $query->getRestrictions()->removeAll();
        $query->select('*')
              ->from($tableName)
              ->where($constraint);
        if (!empty($additionalWhere)) {
            $query->andWhere($additionalWhere);
        }

        if (!empty($groupBy)) {
            $query->groupBy($groupBy);
        }
        if (!empty($orderBy)) {
            $order = explode(' ', $orderBy);
            $query->orderBy($order[0], $order[1] ?? null);
        }
        if (!empty($limit)) {
            $query->setMaxResults((int)$limit);
        }
        $this->statistics['f'][__FUNCTION__]++;
        $this->statistics['t'][$tableName]++;
        $rows = $query->execute()->fetchAll();

        return $this->indexRowsByField($indexField, $rows);
    }

    /**
     * @param Connection $connection
     * @param array $properties
     * @param string $additionalWhere
     * @param string $groupBy
     * @param string $orderBy
     * @param string $limit
     * @param string $indexField
     * @param string|null $tableName
     *
     * @return array
     */
    public function findPropertiesByProperties(
        Connection $connection,
        array $properties,
        $additionalWhere = '',
        $groupBy = '',
        $orderBy = '',
        $limit = '',
        $indexField = 'uid',
        string $tableName = null
    ): array {
        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }

        if (empty($orderBy)) {
            $orderBy = $this->tcaService->getSortingField($tableName);
        }

        if (isset($this->preloadTables[$tableName])) {
            $additionalProperties = $this->parser->parseToPropertyArray($additionalWhere, $tableName);
            if (null !== $additionalProperties) {
                foreach ($properties as $propertyName => $propertyValue) {
                    $properties[$propertyName] = strtolower((string)$propertyValue);
                }
                $properties = array_merge($additionalProperties, $properties);
                return $this->getPreloadedRowsMatchingProperties(
                    $connection,
                    $tableName,
                    $properties,
                    $indexField,
                    $orderBy,
                    (int)$limit
                );
            }
        }

        $query = $connection->createQueryBuilder();
        $query->getRestrictions()->removeAll();
        $query->select('*')
              ->from($tableName);
        if (!empty($additionalWhere)) {
            $query->andWhere($additionalWhere);
        }

        foreach ($properties as $propertyName => $propertyValue) {
            if (null === $propertyValue) {
                $query->andWhere($query->expr()->isNull($propertyName));
            } elseif (is_int($propertyValue) || MathUtility::canBeInterpretedAsInteger($propertyValue)) {
                $query->andWhere($query->expr()->eq($propertyName, $query->createNamedParameter($propertyValue)));
            } else {
                $query->andWhere($query->expr()->like($propertyName, $query->createNamedParameter($propertyValue)));
            }
        }

        if (!empty($groupBy)) {
            $query->groupBy($groupBy);
        }
        if (!empty($orderBy)) {
            $order = explode(' ', $orderBy);
            $query->orderBy($order[0], $order[1] ?? null);
        }
        if (!empty($limit)) {
            $query->setMaxResults((int)$limit);
        }
        $this->statistics['f'][__FUNCTION__]++;
        $this->statistics['t'][$tableName]++;
        $rows = $query->execute()->fetchAll();

        return $this->indexRowsByField($indexField, $rows);
    }

    /**
     * TODO: check if $this->identifierFieldName could be used instead
     *
     * Executes an UPDATE query on the given database connection. This method will
     * overwrite any value given in $properties where uid = $identifier
     *
     * @param Connection $connection
     * @param int|string $identifier
     * @param array $properties
     * @param string|null $tableName
     *
     * @return bool
     */
    protected function updateRecord(
        Connection $connection,
        $identifier,
        array $properties,
        string $tableName = null
    ): bool {
        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }
        // deal with MM records, they have (in2publish internal) combined identifiers
        if (strpos((string)$identifier, ',') !== false) {
            $identifierArray = Record::splitCombinedIdentifier($identifier);

            $connection->update(
                $tableName,
                $properties,
                $identifierArray
            );
        } else {
            $connection->update(
                $tableName,
                $properties,
                ['uid' => $identifier]
            );
        }
        if (0 < $connection->errorCode()) {
            $this->logFailedQuery(__METHOD__, $connection, $tableName);
        }
        return true;
    }

    /**
     * Select all rows from a table. Only useful for tables with few thousand entries.
     *
     * @param Connection $connection
     * @param string $tableName
     * @return array
     */
    protected function findAll(Connection $connection, string $tableName): array
    {
        $query = $connection->createQueryBuilder();
        $query->getRestrictions()->removeAll();
        $query->select('*')->from($tableName);
        $this->statistics['f'][__FUNCTION__]++;
        $this->statistics['t'][$tableName]++;
        return $query->execute()->fetchAll();
    }

    /**
     * Executes an INSERT query on the given database connection. Any value in
     * $properties will be inserted into a new row.
     * if there's no UID it will be set by auto_increment
     *
     * @param Connection $connection
     * @param array $properties
     * @param string|null $tableName
     *
     * @return bool
     */
    protected function addRecord(Connection $connection, array $properties, string $tableName = null): bool
    {
        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }
        $success = (bool)$connection->insert($tableName, $properties);
        if (!$success) {
            $this->logFailedQuery(__METHOD__, $connection, $tableName);
        }
        return $success;
    }

    /**
     * TODO: check if $this->identifierFieldName could be used instead
     *
     * Removes a database row from the given database connection. Executes a DELETE
     * query where uid = $identifier
     * !!! THIS METHOD WILL REMOVE THE MATCHING ROW FOREVER AND IRRETRIEVABLY !!!
     *
     * If you want to delete a row "the normal way" set
     * propertiesArray('deleted' => TRUE) and use updateRecord()
     *
     * @param Connection $connection
     * @param int $identifier
     * @param string|null $tableName
     *
     * @return bool
     * @internal param string $deleteFieldName
     */
    protected function deleteRecord(Connection $connection, $identifier, string $tableName = null)
    {
        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }
        if (strpos((string)$identifier, ',') !== false) {
            $identifierArray = Record::splitCombinedIdentifier($identifier);

            $success = (bool)$connection->delete($tableName, $identifierArray);
            if (!$success) {
                $this->logFailedQuery(__METHOD__, $connection, $tableName);
            }
            return $success;
        } else {
            $success = (bool)$connection->delete($tableName, ['uid' => (int)$identifier]);
            if (!$success) {
                $this->logFailedQuery(__METHOD__, $connection, $tableName);
            }
            return $success;
        }
    }

    /**
     * Does not support identifier array!
     *
     * @param Connection $connection
     * @param string|int $identifier
     * @param string|null $tableName
     *
     * @return bool|int
     */
    protected function countRecord(Connection $connection, $identifier, string $tableName = null, $idFieldName = 'uid')
    {
        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }
        $result = $connection->count(
            '*',
            $tableName,
            [$idFieldName => $identifier]
        );
        if (false === $result) {
            $this->logFailedQuery(__METHOD__, $connection, $tableName);
            return false;
        }
        return (int)$result;
    }

    /**
     * Quote string: escapes bad characters
     *
     * @param string $string
     *
     * @return string
     */
    protected function quoteString($string): string
    {
        return DatabaseUtility::quoteString($string);
    }

    /**
     * Logs a failed database query with all retrievable information
     *
     * @param $method
     * @param Connection $connection
     * @param string|null $tableName
     *
     * @return void
     */
    protected function logFailedQuery($method, Connection $connection, string $tableName = null)
    {
        if (null === $tableName) {
            trigger_error(sprintf(static::DEPRECATION_TABLE_NAME_FIELD, __METHOD__), E_USER_DEPRECATED);
            $tableName = $this->tableName;
        }
        $this->logger->critical(
            $method . ': Query failed.',
            [
                'errno' => $connection->errorCode(),
                'error' => json_encode($connection->errorInfo()),
                'tableName' => $tableName,
            ]
        );
    }

    /**
     * Sets a new index for all entries in $rows. Does not check for duplicate keys.
     * If there are duplicates, the last one is final.
     *
     * @param string $indexField Single field name or comma separated, if more than one field.
     * @param array $rows The rows to reindex
     * @return array The rows with the new index.
     */
    protected function indexRowsByField(string $indexField, array $rows): array
    {
        if (strpos($indexField, ',')) {
            $newRows = [];
            $combinedIdentifier = explode(',', $indexField);

            foreach ($rows as $row) {
                $identifierArray = [];
                foreach ($combinedIdentifier as $identifierFieldName) {
                    $identifierArray[] = $row[$identifierFieldName];
                }
                $newRows[implode(',', $identifierArray)] = $row;
            }
            return $newRows;
        }

        return array_column($rows, null, $indexField);
    }

    protected function getPreloadCache(Connection $connection, string $tableName): array
    {
        $connectionId = spl_object_id($connection);
        if (!array_key_exists($connectionId, $this->preloadCache)) {
            $this->preloadCache[$connectionId] = [];
        }
        if (!array_key_exists($tableName, $this->preloadCache[$connectionId])) {
            $this->preloadCache[$connectionId][$tableName] = $this->findAll($connection, $tableName);
        }
        return $this->preloadCache[$connectionId][$tableName];
    }

    protected function getPreloadedRowsMatchingProperties(
        Connection $connection,
        ?string $tableName,
        array $properties,
        string $indexField,
        string $orderBy,
        int $limit
    ): array {
        $cache = $this->getPreloadCache($connection, $tableName);
        foreach ($cache as $idx => $row) {
            if (!$this->isRowMatchingProperties($row, $properties)) {
                unset($cache[$idx]);
            }
        }
        if ($limit > 0 && $limit < count($cache)) {
            $cache = array_slice($cache, 0, $limit);
        }
        if (!empty($orderBy)) {
            $cache = $this->orderRows($cache, $orderBy);
        }
        return $this->indexRowsByField($indexField, $cache);
    }

    protected function isRowMatchingProperties(array $row, array $properties): bool
    {
        foreach ($properties as $name => $value) {
            if (!array_key_exists($name, $row) || strtolower((string)$row[$name]) !== $value) {
                return false;
            }
        }
        return true;
    }

    protected function orderRows(array $rows, string $orderBy): array
    {
        if (empty($rows)) {
            return $rows;
        }
        $orderArray = [];

        $orderings = GeneralUtility::trimExplode(',', $orderBy, true);
        foreach ($orderings as $ordering) {
            $orderParts = GeneralUtility::trimExplode(' ', $ordering);
            if (!array_key_exists(1, $orderParts)) {
                $orderParts[1] = 'ASC';
            }
            $name = $orderParts[0];
            $order = strtolower($orderParts[1]) === 'desc' ? SORT_DESC : SORT_ASC;

            $orderArray[$name] = $order;
        }

        $params = [];
        foreach (array_keys($orderArray) as $key) {
            foreach ($rows as $row) {
                $params[$key][] = $row[$key];
            }
            $params[] = $orderArray[$key];
        }

        $params[] = &$rows;
        call_user_func_array('array_multisort', $params);
        return $rows;
    }

    /**
     * Since this class is a singleton, this object will be destructed at the very end of any PHP processing.
     * At that point, no queries will be executed anymore and the statistics are complete.
     */
    public function __destruct()
    {
        $this->logger->debug('BasicRepository query statistics', $this->statistics);
    }

    /*************************
     *                       *
     *  GETTERS AND SETTERS  *
     *                       *
     *************************/

    /**
     * @return string
     */
    public function getTableName(): string
    {
        trigger_error(sprintf(static::DEPRECATION_METHOD, __METHOD__), E_USER_DEPRECATED);
        return $this->tableName;
    }

    /**
     * @param string $tableName
     *
     * @return BaseRepository
     */
    public function setTableName($tableName): BaseRepository
    {
        trigger_error(sprintf(static::DEPRECATION_METHOD, __METHOD__), E_USER_DEPRECATED);
        $this->tableName = $tableName;
        return $this;
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    public function replaceTableName($tableName): string
    {
        trigger_error(sprintf(static::DEPRECATION_METHOD, __METHOD__), E_USER_DEPRECATED);
        $replacedTableName = $this->tableName;
        $this->tableName = $tableName;
        return $replacedTableName;
    }

    /**
     * @return string
     */
    public function getIdentifierFieldName(): string
    {
        trigger_error(sprintf(static::DEPRECATION_METHOD, __METHOD__), E_USER_DEPRECATED);
        return $this->identifierFieldName;
    }
}
