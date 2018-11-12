<?php declare(strict_types=1);

namespace GeorgRinger\NewsSync\Service;

use GeorgRinger\NewsSync\Utlity\ConnectionUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Checks if everything is prepared for starting import
 */
class PreparationService
{

    public static $importTables = [
        'tx_news_domain_model_news',
        'sys_file',
        'sys_category',

        'sys_category_record_mm',
        'sys_file_metadata',
        'sys_file_reference',
        'tx_news_domain_model_news_related_mm',
        'tx_news_domain_model_news_tag_mm',
        'tx_news_domain_model_news_ttcontent_mm',
        'tx_news_domain_model_tag',
        'tx_news_domain_model_link',
//        'tt_content',
    ];

    /** @var ConnectionUtility */
    protected $connectionUtility;

    /** @var string */
    protected $prefix = '';

    /** @var array */
    protected $out = [];

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
        $this->connectionUtility = GeneralUtility::makeInstance(ConnectionUtility::class, $prefix);
    }

    public function run(bool &$status): array
    {
        $status = false;
        try {
            $this->checkTablePrefix();
            $this->moveUidToImportField();
            $status = true;
        } catch (\Exception $e) {
            $status = false;
            $this->addResponse('Checks stopped: ' . $e->getMessage(), 1);
        }

        return $this->out;
    }

    protected function checkTablePrefix()
    {
        $tables = $this->connectionUtility->getTables('sync_sys_category');
        foreach ($tables as $tableName) {
            if (StringUtility::beginsWith($tableName, $this->prefix)) {
                $this->addResponse(sprintf('Prefix: %s: ok', $tableName));
            } else {
                $this->addResponse(sprintf('Prefix: %s: no prefix', $tableName), 1);
                throw new \RuntimeException('Table sync');
            }
        }
    }

    protected function moveUidToImportField(): void
    {
        $importFieldName = 'import_id';
        $importFieldName2 = $importFieldName . '_2';

        $variants = ['sync' => $this->prefix, 'live' => ''];
        foreach ($variants as $db => $prefix) {
            foreach (self::$importTables as $tableName) {
                $tableName = $prefix . $tableName;
                $columns = $this->connectionUtility->getColumns($tableName);

                // new fields
                if (!isset($columns[$importFieldName])) {
                    $this->connectionUtility->addImportColumn($tableName, $importFieldName);
                    $this->addResponse(sprintf('Import field: %s/%s: has been added', $db, $tableName), 0);
                } else {
                    $this->addResponse(sprintf('Import field: %s/%s: already exists', $db, $tableName), 2);
                }
                // mm fields need 2nd import field
                if (!isset($columns['pid'])) {
                    if (!isset($columns[$importFieldName2])) {
                        $this->connectionUtility->addImportColumn($tableName, $importFieldName2);
                        $this->addResponse(sprintf('Import field2: %s/%s: has been added', $db, $tableName), 0);
                    } else {
                        $this->addResponse(sprintf('Import field2: %s/%s: already exists', $db, $tableName), 2);
                    }
                }


                if ($prefix !== '') {
                    // no pid means mm table which can be ignored
                    if (!isset($columns['pid'])) {
                        $this->connectionUtility->copyValueFromColumnToColumn($tableName, 'uid_local', $importFieldName);
                        $this->connectionUtility->copyValueFromColumnToColumn($tableName, 'uid_foreign', $importFieldName2);
                    } else {
                        $this->connectionUtility->copyValueFromColumnToColumn($tableName, 'uid', $importFieldName);
                    }
                }
            }
        }
    }

    protected function checkColumnDiff(): void
    {

    }


    private function addResponse(string $text, int $status = 0): void
    {
        switch ($status) {
            case 0:
                $tag = 'info';
                break;
            case 1:
                $tag = 'error';
                break;
            case 2:
                $tag = 'comment';
                break;
        }
        $text = '<' . $tag . '>' . $text . '</' . $tag . '>';
        $this->out[] = $text;
    }
}