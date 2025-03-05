<?php

namespace Ody\Websocket\Commands;

use Ody\Core\Foundation\Console\Style;
use Ody\Core\Server\Dependencies;
use Ody\Swoole\HotReload\Watcher;
use Ody\Websocket\WebsocketServerState;
use Swoole\Process;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
    private $serverState;
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
        $this->serverState = WebsocketServerState::getInstance();

        if (!Dependencies::check($this->io)) {
            return Command::FAILURE;
        }

        if ($this->serverState->websocketServerIsRunning()) {
            if (!$this->handleRunningServer($input, $output)) {
                return Command::FAILURE;
            }
        }

        /*
         * create watcher server
         */
        if ($input->getOption('watch')) {
            (new Process(function (Process $process) {
                $this->serverState->setWatcherProcessId($process->pid);
                (new Watcher())->start();
            }))->start();
        }

        /*
         * create and start server
         */
        \Ody\Websocket\WsServer::init()
            ->createServer(config('websocket'))
            ->setServerConfig(config('websocket.additional'))
            ->registerCallbacks(config("websocket.callbacks"))
            ->start($input->getOption('daemonize'));

        return Command::SUCCESS;
    }

    private function handleRunningServer(InputInterface $input, OutputInterface $output): bool
    {
        $this->io->error('failed to listen server port[' . config('websocket.host') . ':' . config('websocket.port') . '], Error: Address already', true);

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
