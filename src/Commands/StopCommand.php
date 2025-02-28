<?php
declare(strict_types=1);

namespace Ody\Websocket\Commands;

use Ody\Core\Console\Style;
use Ody\Swoole\ServerState;
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
        $serverState = ServerState::getInstance();
        $io = new Style($input, $output);

        if (!$serverState->websocketServerIsRunning()){
            $io->error('server is not running...' , true);
            return self::FAILURE;
        }

        $serverState->killProcesses([
            $serverState->getWebsocketMasterProcessId(),
            $serverState->getWebsocketManagerProcessId(),
            $serverState->getWatcherProcessId(),
            ...$serverState->getWebsocketWorkerProcessIds()
        ]);

        $serverState->clearWebsocketProcessIds();


        sleep(1);

        $io->success('Stopped websocket server...' , true);
        return self::SUCCESS;
    }
}
