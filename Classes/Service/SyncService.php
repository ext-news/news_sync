<?php

namespace GeorgRinger\NewsSync\Service;

use GeorgRinger\NewsSync\Utlity\ConnectionUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SyncService
{
    protected $prefix = '';

    /** @var ConnectionUtility */
    protected $connectionUtility;

    public static $baseTables = [
        'sys_category',
        'tx_news_domain_model_news',
        'tx_news_domain_model_tag',
        'tx_news_domain_model_link',
        'sys_file',
        'sys_file_metadata',
        'sys_file_reference',
    ];

    public static $relationTables = [
        'sys_category_record_mm' => [
            'uid_local' => 'sys_category',
            'uid_foreign' => 'tx_news_domain_model_news',
        ],
//        'sys_file_metadata',
//        'sys_file_reference',
        'tx_news_domain_model_news_related_mm' => [
            'uid_local' => 'tx_news_domain_model_news',
            'uid_foreign' => 'tx_news_domain_model_news',
        ],
        'tx_news_domain_model_news_tag_mm' => [
            'uid_local' => 'tx_news_domain_model_tag',
            'uid_foreign' => 'tx_news_domain_model_news',
        ],
//        'tx_news_domain_model_news_ttcontent_mm',

    ];


    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
        $this->connectionUtility = GeneralUtility::makeInstance(ConnectionUtility::class, $prefix);
    }

    public function run()
    {
        $this->cleanupSysFile();
        $this->handleBaseTables();
        $this->handleRelationTables();

        $this->importFal();
        $this->fixLanguageParent();

    }

    protected function handleBaseTables(): void
    {
        foreach (self::$baseTables as $tableName) {
            $targetConnection = $this->connectionUtility->getConnectionForTable($tableName);

            $columnsOfTarget = $targetConnection->getSchemaManager()->listTableColumns($tableName);

            $toBeImportedRows = $this->connectionUtility->getAllFromTables($this->prefix . $tableName);

            foreach ($toBeImportedRows as $toBeImportedRow) {
                $newRow = array_intersect_key($toBeImportedRow, $columnsOfTarget);
                $id = (int)$newRow['uid'];
                unset($newRow['uid']);

                $where = ['import_id' => $id];
                if (isset($toBeImportedRow['sys_language_uid'])) {
                    $where['sys_language_uid'] = $toBeImportedRow['sys_language_uid'];
                }
                $possibleTargetRow = $this->getPossibleImportRow($targetConnection, $tableName, $where);

                if ($possibleTargetRow['uid']) {
                    $targetConnection->update($tableName, $newRow, ['uid' => $possibleTargetRow['uid']]);
                } else {
                    $targetConnection->insert($tableName, $newRow);
                }
            }
        }
    }

    protected function fixLanguageParent()
    {
        $tables = ['tx_news_domain_model_news', 'sys_category', 'sys_file_reference'];
        foreach ($tables as $tableName) {
            $targetConnection = $this->connectionUtility->getConnectionForTable($tableName);

            $qb = $this->connectionUtility->getConnectionForTable($tableName)->createQueryBuilder();
            $qb->getRestrictions()->removeAll();

            $rows = $qb->select('*')
                ->from($tableName)
                ->where(
                    $qb->expr()->gt('import_id', $qb->createNamedParameter(0, \PDO::PARAM_INT)),
                    $qb->expr()->gt('sys_language_uid', $qb->createNamedParameter(0, \PDO::PARAM_INT))
                )
                ->execute()
                ->fetchAll();
            foreach ($rows as $row) {
                $relationRow = $this->connectionUtility->getSingleRow($tableName, $row['l10n_parent'], 'import_id');
                if (!is_array($relationRow) || empty($relationRow)) {
                    throw new \RuntimeException(sprintf('Table %s with uid %s, parent relation of %s not found', $tableName, $row['uid'], $row['l10n_parent']));
                }
                $targetConnection->update($tableName, ['l10n_parent' => $relationRow['uid']], ['uid' => $row['uid']]);
            }
        }

    }

    protected function importFal()
    {
        // Fix relations
        $tables = [
            'sys_file_reference' => [
                'uid_local' => 'sys_file',
                'uid_foreign' => 'tx_news_domain_model_news'
            ],
            'sys_file_metadata' => [
                'file' => 'sys_file'
            ]
        ];

        foreach ($tables as $tableName => $mapping) {
            $targetConnection = $this->connectionUtility->getConnectionForTable($tableName);
            $qb = $this->connectionUtility->getConnectionForTable($tableName)->createQueryBuilder();
            $qb->getRestrictions()->removeAll();

            $rows = $qb->select('*')
                ->from($tableName)
                ->where(
                    $qb->expr()->gt('import_id', $qb->createNamedParameter(0, \PDO::PARAM_INT))
                )
                ->execute()
                ->fetchAll();
            foreach ($rows as $row) {
                $this->updateRelationData($row, $mapping);

                $targetConnection->update($tableName, $row, ['uid' => $row['uid']]);
            }
        }
    }

    protected function cleanupSysFile()
    {
        $tableName = $this->prefix . 'sys_file_reference';
        $sysFileReferenceConnection = $this->connectionUtility->getConnectionForTable($tableName);
        $sysFileReferenceQueryBuilder = $sysFileReferenceConnection->createQueryBuilder();
        $sysFileReferenceQueryBuilder->getRestrictions()->removeAll();
        $sysFileReferenceQueryBuilder->delete($tableName)
            ->where($sysFileReferenceQueryBuilder->expr()->neq('tablenames', $sysFileReferenceQueryBuilder->createNamedParameter('tx_news_domain_model_news', \PDO::PARAM_STR)))
            ->execute();

        $sysFileReferenceQueryBuilder = $sysFileReferenceConnection->createQueryBuilder();
        $sysFileReferenceQueryBuilder->getRestrictions()->removeAll();
        $allRows = $sysFileReferenceQueryBuilder
            ->select('uid', 'uid_local', 'import_id')
            ->from($tableName)
            ->execute()->fetchAll();

        $targetConnectionSysFile = $this->connectionUtility->getConnectionForTable($this->prefix . 'sys_file');
        $targetConnectionSysFileMetadata = $this->connectionUtility->getConnectionForTable($this->prefix . 'sys_file_metadata');
        foreach ($allRows as $row) {
            $targetConnectionSysFile->update($this->prefix . 'sys_file', ['pid' => 123456789], ['uid' => $row['uid_local']]);
            $targetConnectionSysFileMetadata->update($this->prefix . 'sys_file_metadata', ['pid' => 123456789], ['file' => $row['uid']]);
        }

        // remove all others
        $targetConnectionSysFile->delete($this->prefix . 'sys_file', ['pid' => 0]);
        $targetConnectionSysFileMetadata->delete($this->prefix . 'sys_file_metadata', ['pid' => 0]);

        // restore pid
        $targetConnectionSysFile->update($this->prefix . 'sys_file', ['pid' => 0], ['pid' => 123456789]);
        $targetConnectionSysFileMetadata->update($this->prefix . 'sys_file_metadata', ['pid' => 0], ['pid' => 123456789]);
    }

    protected function handleRelationTables(): void
    {
        foreach (self::$relationTables as $tableName => $mapping) {
            $targetConnection = $this->connectionUtility->getConnectionForTable($tableName);

            $columnsOfTarget = $targetConnection->getSchemaManager()->listTableColumns($tableName);

            $toBeImportedRows = $this->connectionUtility->getAllFromTables($this->prefix . $tableName);

            foreach ($toBeImportedRows as $toBeImportedRow) {
                $newRow = array_intersect_key($toBeImportedRow, $columnsOfTarget);

                switch ($tableName) {
                    case 'sys_category_record_mm':
                        $where = [
                            'import_id' => $newRow['import_id'],
                            'import_id_2' => $newRow['import_id_2'],
                            'tablenames' => $newRow['tablenames'],
                            'fieldname' => $newRow['fieldname'],
                        ];
                        $possibleTargetRow = $this->getPossibleImportRow($targetConnection, $tableName, $where);
                        $this->updateRelationData($newRow, $mapping);
                        break;
                    case 'tx_news_domain_model_news_related_mm':
                    case 'tx_news_domain_model_news_tag_mm':
                        $where = [
                            'import_id' => $newRow['import_id'],
                            'import_id_2' => $newRow['import_id_2'],
                        ];
                        $possibleTargetRow = $this->getPossibleImportRow($targetConnection, $tableName, $where);
                        $this->updateRelationData($newRow, $mapping);
                        break;
                    default:
                        throw new \RuntimeException(sprintf('Table "%s" is not yet implemented in importer!', $tableName));
                }

                if (!empty($possibleTargetRow)) {
//                    echo 'update'.LF;
//                    print_r($newRow);die;
                    $targetConnection->update($tableName, $newRow, $where);
                } else {
//                    echo 'insert';
                    $targetConnection->insert($tableName, $newRow);
                }

//                print_R($newRow);
//                die;
            }
        }
    }

    protected function updateRelationData(array &$newRow, $configuration)
    {
        foreach ($configuration as $field => $table) {
            $relationRow = $this->connectionUtility->getSingleRow($table, $newRow[$field], 'import_id');
            if (is_array($relationRow) && !empty($relationRow)) {
                $newRow[$field] = $relationRow['uid'];
            }
        }
    }

    protected function getNewIdAfterImport(string $tableName, int $id)
    {

    }

    /**
     * @param $targetConnection
     * @param string $tableName
     * @param array $where
     * @return array
     */
    protected function getPossibleImportRow($targetConnection, string $tableName, array $where)
    {
        $possibleTargetRowQueryBuilder = $targetConnection->createQueryBuilder();
        $whereClause = [];
        foreach ($where as $field => $value) {
            if (is_int($value)) {
                $whereClause[] = $possibleTargetRowQueryBuilder->expr()->eq($field, $possibleTargetRowQueryBuilder->createNamedParameter($value, \PDO::PARAM_INT));
            } elseif (is_string(($value))) {
                $whereClause[] = $possibleTargetRowQueryBuilder->expr()->eq($field, $possibleTargetRowQueryBuilder->createNamedParameter($value, \PDO::PARAM_STR));
            }
        }

        $possibleTargetRowQueryBuilder->getRestrictions()->removeAll();
        $possibleTargetRow = $possibleTargetRowQueryBuilder
            ->select('*')
            ->from($tableName)
            ->where(
                ...$whereClause
            )->execute()->fetch();
        return $possibleTargetRow;
    }


}