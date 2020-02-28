<?php
namespace BloomDesign\GhyzamSync\Command;

// define('_PS_ADMIN_DIR_', getcwd());
// include('config/config.inc.php');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

include(__DIR__ . '/../../ghyzamsync.php');

class RunSyncCommand extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('ghyzamsync:runsync')

            // the short description shown while running "php bin/console list"
            ->setDescription('Starts the sync process with RD04')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Starts the sync process with RD04')
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->write('Test output');

        $rd_service = new \GhyzamCatalog(\GhyzamSync::getConfigFormValues());
        $order_result = $rd_service->syncProducts();
        $rd_service->end();

        \GhyzamSync::writeLog('Command called', $order_result);
    }
}

?>