<?php

namespace GeorgRinger\NewsSync\Utlity;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ConnectionUtility
{

    /** @var string */
    protected $prefix = '';

    /**
     * @param string $prefix
     */
    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    public function getAllFromTables(string $tableName, bool $orderBySysLanguage = false): array
    {
        $queryBuilder = $this->getConnectionForTable($tableName)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        if ($orderBySysLanguage) {
            return $queryBuilder
                ->select('*')
                ->from($tableName)
                ->orderBy('sys_language_uid', 'asc')
                ->execute()
                ->fetchAll();
        }

        return $queryBuilder
            ->select('*')
            ->from($tableName)
            ->execute()
            ->fetchAll();
    }

    public function getSingleRow(string $tableName, int $id, string $idField = 'uid')
    {
        $qb = $this
            ->getConnectionForTable($tableName)
            ->createQueryBuilder();
        $qb->getRestrictions()->removeAll();

        $row = $qb->select('*')
            ->from($tableName)
            ->where($qb->expr()->eq(
                $idField,
                $qb->createNamedParameter($id, \PDO::PARAM_INT)
            ))->execute()->fetch();

        return $row;
    }

//    public function getImportedRecord(string $tableName, int $importId, string $importSource = ''): array
//    {
//        $importSource = $importSource ?: $this->prefix;
//
//        $qb = $this->getConnectionForTable($tableName)->createQueryBuilder();
//        $qb->getRestrictions()->removeAll();
//
//        $row = $qb->select('*')
//            ->from($tableName)
//            ->where(
//                $qb->expr()->eq('import_id', $qb->createNamedParameter($importId, \PDO::PARAM_INT)),
//                $qb->expr()->eq('import_source', $qb->createNamedParameter($importSource, \PDO::PARAM_STR))
//            )->execute()->fetch();
//
//        return $row;
//    }

    public function insert(string $tableName, array $fields): int
    {
        return $this->getConnectionForTable($tableName)
            ->insert($tableName, $fields);
    }

    public function getColumns(string $tableName): array
    {
        return $this->getConnectionForTable($tableName)
            ->getSchemaManager()->listTableColumns($tableName);
    }

    public function getTables(string $tableName): array
    {
        return $this->getConnectionForTable($tableName)
            ->getSchemaManager()->listTableNames();
    }

    public function addImportColumn(string $tableName, string $fieldName)
    {
        $connection = $this->getConnectionForTable($tableName);
        $connection->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $fieldName . ' INT DEFAULT 0 NOT NULL;');
    }

    public function copyValueFromColumnToColumn(string $tableName, string $from, string $to)
    {
        $connection = $this->getConnectionForTable($tableName);
        $connection->exec('UPDATE ' . $tableName . ' SET ' . $to . '=' . $from . ';');
    }

    /**
     * @param string $tableName
     * @return Connection
     */
    public function getConnectionForTable(string $tableName): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($tableName);
    }
}