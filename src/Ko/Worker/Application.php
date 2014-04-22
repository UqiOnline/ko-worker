<?php
namespace Ko\Worker;

use Closure;
use Ko\Process;
use Ko\ProcessManager;
use Symfony\Component\Yaml\Yaml;
use Ulrichsg\Getopt\Getopt;
use Ulrichsg\Getopt\Option;

class Application
{
    const VERSION = '1.0.0';

    /**
     * @var Getopt
     */
    protected $opts;

    /**
     * @var ProcessManager
     */
    protected $processManager;

    /**
     * @param ProcessManager $processManager
     */
    public function setProcessManager($processManager)
    {
        $this->processManager = $processManager;
    }

    public function run()
    {
        $this->buildCommandLineOptions();
        $this->parseCommandLine();
        $this->prepareMasterProcess();

        for ($i = 0; $i < (int)$this->opts['w']; $i++) {
            $closure = $this->getChildCallable();

            if (isset($this->opts['f'])) {
                $this->processManager->fork($closure);
            } else {
                $this->processManager->spawn($closure);
            }
        }

        $this->processManager->wait();
        exit(0);
    }

    protected function buildCommandLineOptions()
    {
        $this->opts = new Getopt(
            [
                (new Option('w', 'workers', Getopt::REQUIRED_ARGUMENT))
                    ->setDefaultValue(1)
                    ->setDescription('Worker process count'),
                (new Option('c', 'config', Getopt::REQUIRED_ARGUMENT))
                    ->setDefaultValue('config.yaml')
                    ->setDescription('Worker configuration file'),
                (new Option('q', 'queue', Getopt::REQUIRED_ARGUMENT))
                    ->setDescription('Consumer queue name'),
                (new Option('d', 'demonize', Getopt::NO_ARGUMENT))
                    ->setDescription('Run application as daemon'),
                (new Option('f', 'fork', Getopt::NO_ARGUMENT))
                    ->setDescription('Use fork instead of spawn child process')
            ]
        );
    }

    protected function parseCommandLine()
    {
        try {
            $this->opts->parse();
            if ($this->opts->count() === 0) {
                $this->printAbout();
                exit(0);
            }
        } catch (\UnexpectedValueException $e) {
            echo "Error: " . $e->getMessage() . PHP_EOL;
            $this->printAbout();
            exit(1);
        }
    }

    protected function printAbout()
    {
        echo 'Ko-worker version ' . self::VERSION . PHP_EOL;
        echo $this->opts->getHelpText();
    }

    protected function prepareMasterProcess()
    {
        $this->processManager->setProcessTitle('ko-worker: master process');
        if (isset($this->opts['d'])) {
            $this->processManager->demonize();
        }
    }

    protected function getChildCallable()
    {
        $config = Yaml::parse(file_get_contents($this->opts['config']));
        $queue = $this->opts['q'];

        if (empty($queue)) {
            throw new \DomainException('You must declare queue name');
        }

        if (!isset($config['consumers'][$queue]['class'])) {
            throw new \DomainException('You must declare a class for the job');
        }

        return Closure::bind(
            function (Process $process) use ($config, $queue) {
                $child = new Child();
                $child->setConfig($config);
                $child->setName($queue);
                $child->setExecutorClass($config['consumers'][$queue]['class']);
                $child->run($process);
            },
            null
        );
    }
}