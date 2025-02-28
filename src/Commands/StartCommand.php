<?php

namespace Ody\Websocket\Commands;

use Ody\Core\Console\Style;
use Ody\Core\Server\Dependencies;
use Ody\Core\Server\Http;
use Ody\Swoole\HotReload\Watcher;
use Ody\Swoole\ServerState;
use Swoole\Process;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[AsCommand(
    name: 'websockets:start',
    description: 'start http server'
)]
class StartCommand extends Command
{
    private ServerState $serverState;
    private Style $io;

    protected function configure(): void
    {
        $this->addOption(
            'daemonize',
            'd',
            InputOption::VALUE_NONE,
            'The program works in the background'
        )->addOption(
            'watch',
            'w',
            InputOption::VALUE_NONE,
            'If there is a change in the program code, it applies the changes instantly'
        );
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /*
         * load Ody style
         */
        $this->io = new Style($input, $output);

        /*
         * Get a server state instance
         */
        $this->serverState = ServerState::getInstance();

        if (!Dependencies::check($this->io)) {
            return Command::FAILURE;
        }

        if ($this->serverState->websocketServerIsRunning()) {
            if (!$this->handleRunningServer($input, $output)) {
                return Command::FAILURE;
            }
        }

        $listenMessage = "listen on ws://" . config('websockets.host') . ':' . config('websockets.port');

        /*
         * send running server
         * send listen message
         */
        $this->io->success('Websocket server server running…');
        $this->io->info($listenMessage, true);

        /*
         * check if exist daemonize not send general information
         */
        if (!$input->getOption('daemonize')) {
            $serverSocketType = match (config('websockets.sock_type')) {
                SWOOLE_SOCK_TCP => 'TCP',
                SWOOLE_SOCK_UDP => 'UDP',
                default => 'other type'
            };
          
            /*
             * create general information table
             */
            $table = new Table($output);
            $table
                ->setHeaderTitle('general information')
                ->setHeaders([
                    '<fg=#FFCB8B;options=bold> PHP VERSION </>',
                    '<fg=#FFCB8B;options=bold> ODY VERSION </>',
                    '<fg=#FFCB8B;options=bold> WORKER COUNT </>',
                    '<fg=#FFCB8B;options=bold> SOCKET TYPE </>',
                    '<fg=#FFCB8B;options=bold> WATCH MODE </>'
                ])
                ->setRows([
                    [
                        '<options=bold> ' . PHP_VERSION . '</>',
                        '<options=bold> ' . ODY_VERSION . ' </>',
                        '<options=bold> ' . config('websockets.additional.worker_num') . '</>',
                        "<options=bold> $serverSocketType</>",
                        $input->getOption('watch') ? '<fg=#C3E88D;options=bold> ACTIVE </>' : "<fg=#FF5572;options=bold> DEACTIVE </>"
                    ],
                ]);
            $table->setHorizontal();
            $table->render();

            /*
             * send info message for stop server
             */
            $this->io->info('Press Ctrl+C to stop the server');

            /*
             * create watcher server
             */
            if ($input->getOption('watch')) {
                (new Process(function (Process $process) {
                    $this->serverState->setWatcherProcessId($process->pid);
                    (new Watcher())->start();
                }))->start();
            }
        }

        /*
         * create and start server
         */
        \Ody\Websocket\Server::init()
            ->createServer()
            ->start($input->getOption('daemonize'));

        return Command::SUCCESS;
    }

    private function handleRunningServer(InputInterface $input, OutputInterface $output): bool
    {
        $this->io->error('failed to listen server port[' . config('websockets.host') . ':' . config('websockets.port') . '], Error: Address already', true);

        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'Do you want the server to terminate? (defaults to no)',
            ['no', 'yes'],
            0
        );
        $question->setErrorMessage('Your selection is invalid.');

        $answer = $helper->ask($input, $output, $question);


        if ($answer != 'yes') {
            return false;
        }

        posix_kill($this->serverState->getMasterProcessId(), SIGTERM);
        posix_kill($this->serverState->getManagerProcessId(), SIGTERM);

        $watcherProcessId = $this->serverState->getWatcherProcessId();
        if (!is_null($watcherProcessId) && posix_kill($watcherProcessId, SIG_DFL)) {
            posix_kill($watcherProcessId, SIGTERM);
        }

        foreach ($this->serverState->getWorkerProcessIds() as $processId) {
            posix_kill($processId, SIGTERM);
        }

        sleep(1);

        return true;
    }
}
