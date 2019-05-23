<?php
namespace Prime\DataTransferBundle\Command;

use Acrnogor\DataTransferBundle\Traits\DatabaseConnectionTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class FetchCommand extends AbstractCommand
{
    /**
     * Regex to check if the remote dump is ok
     * must start with '-- MySQL dump'
     */
    const VALID_DUMP_REGEX_1 = '/^\-\- MySQL dump/';

    /**
     * Regex to check if the remote dump is ok
     * must end with '-- Dump completed'
     */
    const VALID_DUMP_REGEX_2 = '/\-\- Dump completed on\s+\d*\-\d*\-\d*\s+\d+\:\d+\:\d+[\r\n\s\t]*$/';

    const MYSQL_CLI_WARNING_MESSAGE = 'mysqldump: [Warning] Using a password on the command line interface can be insecure.';

    /** Trait for determine the local database connection based on a given siteaccess */
    use DatabaseConnectionTrait;

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('data-transfer:fetch');
        $this->setDescription('Fetch remote database and files from configured system.');
        $this->addOption('db-only', 'db-only', InputOption::VALUE_NONE, 'Only transfer the database, not the files.');
        $this->addOption('files-only', 'files-only', InputOption::VALUE_NONE, 'Only transfer the files, not the database.');
    }

    /**
     * Execute the command
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input Input
     * @param \Symfony\Component\Console\Output\OutputInterface $output Output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        // Fetch and import live database
        if (!$input->getOption('files-only')) {
            try {
                $this->fetchDatabase();
            } catch (\Exception $exc) {
                $this->progressErr($exc->getMessage());
                $this->progressDone();

                return 1;
            }
            $this->progressDone();
        }

        // fetch live data files
        if (!$input->getOption('db-only')) {
            try {
                $this->fetchFiles();
            } catch (\Exception $exc) {
                $this->progressErr($exc->getMessage());
                $this->progressDone();

                return 1;
            }
            $this->progressDone();
        }
    }

    /**
     * Log in to the remote server and dump the database.
     */
    protected function fetchDatabase()
    {
        $this->output->writeln('Fetching database');

        // Prepare remote command
        $remoteHost = $this->getParam('remote.host');
        $remoteUser = $this->getParam('remote.user');
        $remoteDir  = $this->getParam('remote.dir');
        $remoteEnv  = $this->getParam('remote.env');
        $remotePort = $this->getParam('remote.port');
        $consoleCmd = $this->getParam('console_script');
        $options    = $this->getParam('ssh.options');

        // Check for ssh proxy
        $sshProxyString = $this->getSshProxyOption();
        if ($sshProxyString) {
            $options[] = $sshProxyString;
        }

        $exportCmd = sprintf(
            'ssh %s %s@%s "cd %s ; %s data-transfer:export %s"',
            implode(' ', $options),
            $remoteUser,
            $remoteHost,
            $remoteDir,
            $consoleCmd,
            $remoteEnv ? '--env=' . $remoteEnv : ''
        );

        $process = new Process($exportCmd);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('Export on remote didn\'t go so well...');
        }

        $this->progress();

        $remoteFile = sprintf('%s/var/cache/%s/db-dump.sql', $remoteDir, $remoteEnv);
        $localFile = sprintf('%s/db-dump.sql', $this->getContainer()->getParameter('kernel.cache_dir'));

        $downloadCmd = sprintf(
            'scp -P %s %s@%s:%s %s',
            $remotePort,
            $remoteUser,
            $remoteHost,
            $remoteFile,
            $localFile
        );
        $process = new Process($downloadCmd);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('Unable to transfer dump from remote server to localhost');
        }
        $this->progressOk();

        // Import database
        $this->output->writeln('Importing database');

        // Fetch db connection data
        $dbParams = $this->getDatabaseParameter();

        // Prepare command line parameters
        $parameters = array();
        $parameters[] = escapeshellarg($dbParams['dbName']);
        $parameters[] = sprintf('--user=%s', escapeshellarg($dbParams['dbUser']));
        $parameters[] = sprintf('--password=%s', escapeshellarg($dbParams['dbPass']));
        $parameters[] = sprintf('--host=%s', escapeshellarg($dbParams['dbHost']));

        // Add additional arguments
        if (isset($dbParams['databaseImportArguments'])) {
            foreach ($dbParams['databaseImportArguments'] as $argument) {
                $parameters[] = $argument;
            }
        }

        // Import Dump
        $importCmd = sprintf('mysql %s < %s 2>&1', implode(' ', $parameters), escapeshellarg($localFile));
        $this->progress();

        $process = new Process($importCmd);
        $process->setTimeout(null);
        // Update status for each megabyte
        $process->run();
        $this->progress();

        if (!$process->isSuccessful()) {
            throw new \Exception(sprintf('Error importing database: %s %s', $process->getOutput(), $process->getErrorOutput()));
        }
        $this->progressOk();
        $this->progress();
    }

    /**
     * Fetch files from remote server
     */
    protected function fetchFiles()
    {
        // Fetch folders to rsync
        $folders = $this->getParam('folders');
        $rsyncOptions = $this->getParam('rsync.options');
        $sshOptions = $this->getParam('ssh.options');

        // Check for ssh proxy
        $sshProxyString = $this->getSshProxyOption();
        if ($sshProxyString) {
            $sshOptions[] = $sshProxyString;
        }

        // Fetch params
        $remoteHost = $this->getParam('remote.host');
        $remoteUser = $this->getParam('remote.user');
        $remoteDir = $this->getParam('remote.dir');

        // Loop over the folders, to be transfered
        foreach ($folders as $src => $dst) {
            // If src = numeric, then indiced array was taken. detect folder automatically
            if (is_numeric($src)) {
                $src = $dst;
                $dst = dirname($src);
            }

            // Prepare command - added LK switch to not include symlinks, chances are they are not the same on remote
            // and local server - so they will not work properly -- instead follow symlink and create hard copies
            $cmd = sprintf('rsync -LK -P %s -e \'ssh %s\' %s@%s:%s/%s %s/ 2>&1', implode(' ', $rsyncOptions), implode(' ', $sshOptions), $remoteUser, $remoteHost, $remoteDir, $src, $dst);

            // Run (with callback to update those fancy dots
            $process = new Process($cmd);
            $process->setTimeout(null);

            $lastCnt = 0;
            $counting = true;
            $this->output->writeln('Counting files');
            $process->run(function ($type, $buffer) use (&$lastCnt, &$counting) {
                if ($type == Process::OUT) {
                    if (preg_match('/(\d+)\sfiles.../', $buffer, $matches)) {
                        // Still counting
                        $diff = ($matches[1] - $lastCnt) / 100;
                        for ($i = 0; $i < $diff; $i++) {
                            $this->progress();
                        }
                        $lastCnt = $matches[1];
                    } elseif (preg_match('/xfe?r#(\d+), to\-che?c?k=(\d+)\/(\d+)/', $buffer, $matches)) {
                        // Finished counting, now downloading
                        if ($counting) {
                            $counting = false;
                            $this->progressDone();
                            $this->output->writeln(sprintf('Found %d files/folders', $lastCnt));
                            $this->output->writeln('');
                            $this->output->writeln('Syncing files');
                            $lastCnt = 0;
                        }

                        $diff = floor(($matches[1] - $lastCnt) / 100);
                        for ($i = 0; $i < $diff; $i++) {
                            $this->progress();
                        }
                        if ($diff) {
                            $lastCnt += $diff * 100;
                        }
                    }
                }
            });
            if ($counting) {
                $this->output->writeln('Files already up-to-date');
            }

            if (!$process->isSuccessful()) {
                throw new \Exception(sprintf('Error fetching files: %s %s', $process->getOutput(), $process->getErrorOutput()));
            }

            $this->progressOk();
        }
    }

    /**
     * Fetch a parameter from config
     *
     * @param String $param Name of the parameter (without the ugly prefixes)
     *
     * @return mixed
     */
    protected function getParam($param)
    {
        return $this->getContainer()->getParameter('data_transfer_bundle.' . $param);
    }

    /**
     * Find ssh proxy options and return as ssh option string
     *
     * @return String
     */
    protected function getSshProxyOption()
    {
        // Check for ssh proxy
        $sshProxyHost = $this->getParam('ssh.proxy.host');
        $sshProxyUser = $this->getParam('ssh.proxy.user');
        $sshProxyOptions = $this->getParam('ssh.proxy.options');

        // No host or user -> no proxy
        if (!$sshProxyHost || !$sshProxyUser) {
            return '';
        }

        // Build option string
        $opt = sprintf('-o ProxyCommand="ssh -W %%h:%%p %s %s@%s"', implode(' ', $sshProxyOptions), $sshProxyUser, $sshProxyHost);

        return $opt;
    }
}
