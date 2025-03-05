<?php
declare(strict_types=1);

namespace Ody\Websocket\Commands;

use Ody\Core\Foundation\Console\Style;
use Ody\Websocket\WebsocketServerState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'websocket:stop' ,
    description: 'stop websocket server')
]
class StopCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverState = WebsocketServerState::getInstance();
        $io = new Style($input, $output);

        if (!$serverState->websocketServerIsRunning()){
            $io->error('server is not running...' , true);
            return self::FAILURE;
        }

        $serverState->killProcesses([
            $serverState->getMasterProcessId(),
            $serverState->getManagerProcessId(),
            $serverState->getWatcherProcessId(),
            ...$serverState->getWorkerProcessIds()
        ]);

        $serverState->clearProcessIds();


        sleep(1);

        $io->success('Stopped websocket server...' , true);
        return self::SUCCESS;
    }
}
