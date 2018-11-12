<?php declare(strict_types=1);

namespace GeorgRinger\NewsSync\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SyncImagesCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Sync news images');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {

        $rows = $this->getAllRows();
        $progressBar = new ProgressBar($output, count($rows));

        $progressBar->start();

        foreach ($rows as $row) {
            $this->syncSingleFile($row);

            $progressBar->advance();
        }

        $progressBar->finish();
        $output->write('');
    }

    protected function syncSingleFile(array $sysFileRow)
    {
        $filePath = 'content' . $sysFileRow['identifier'];
        $localFilePath = PATH_site . $filePath;
        $domain = '';
        die('no domain yet set, not currently configurable, do it in SyncImagesCommand, sorry');
        $remoteFilePath = $domain . $filePath;
        if (is_file($localFilePath)) {
            return;
        }

        // create dir first
        $fileInfo = pathinfo($localFilePath);
        if (!is_dir($fileInfo['dirname'])) {
            GeneralUtility::mkdir_deep($fileInfo['dirname']);
        }

        // fetch & write file
        $fileContent = GeneralUtility::getUrl($remoteFilePath);
        if ($fileContent) {
            GeneralUtility::writeFile($localFilePath, $fileContent, true);
        }
    }

    protected function getAllRows()
    {
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();

        $rows = $qb->select('*')
            ->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter(2, \PDO::PARAM_INT))
            )
            ->execute()
            ->fetchAll();
        return $rows;
    }


}
