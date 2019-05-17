<?php

namespace Acrnogor\DataTransferBundle\Command;

use Acrnogor\DataTransferBundle\Traits\DatabaseConnectionTrait;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ExportCommand extends ContainerAwareCommand
{
    use DatabaseConnectionTrait;

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('data-transfer:export');
        $this->setDescription('Dump SQL database to stdout');
    }

    /**
     * Execute the command
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input  Input
     * @param \Symfony\Component\Console\Output\OutputInterface $output Output
     *
     * @throws \Exception
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Fetch db connection data
        $dbParams = $this->getDatabaseParameter();

        // Prepare command line parameters
        $parameters = Array();
        $parameters[] = escapeshellarg($dbParams['dbName']);
        $parameters[] = sprintf('--user=%s', escapeshellarg($dbParams['dbUser']));
        $parameters[] = sprintf('--password=%s', escapeshellarg($dbParams['dbPass']));
        $parameters[] = sprintf('--host=%s', escapeshellarg($dbParams['dbHost']));

        // Add additional arguments
        if (isset($dbParams['databaseExportArguments'])) {
            foreach ($dbParams['databaseExportArguments'] as $argument) {
                $parameters[] = $argument;
            }
        }

        $filename = null;
        $folder = $this->getContainer()->getParameter('kernel.cache_dir');

        $filename = sprintf('%s/db-dump.sql', $folder);

        $parameters[] = escapeshellarg('-q');
        $parameters[] = escapeshellarg(sprintf('--result-file=%s', $filename));

        // cleanup old dumps to make some space for new ones
        $this->cleanupOldDumps();

        // call mysqldump
        $cmd = sprintf('mysqldump %s', implode(' ', $parameters));

        // Execute command
        $process = new Process($cmd);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception(sprintf("Error dumping database:\n%s", $process->getOutput()));
        }
    }

    /**
     * Cleanup db dump from cache folder, that are older than 24h
     */
    protected function cleanupOldDumps()
    {
        // Define "old" as 24h in the past
        $old = time() - 24 * 60 * 60;

        $folder = $this->getContainer()->getParameter('kernel.cache_dir');
        foreach (glob($folder.'/db-dump-*.sql') as $dump) {
            if (!preg_match('/db\-dump\-(\d*)\.sql$/', $dump, $matches)) {
                continue;
            }
            if ($matches[1] < $old) {
                $process = new Process(sprintf('rm %s', $dump));
                $process->run();
            }
        }
    }
} 
