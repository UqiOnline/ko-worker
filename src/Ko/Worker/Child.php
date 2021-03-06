<?php
/**
 * The MIT License
 *
 * Copyright (c) 2014 Nikolay Bondarenko
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * PHP version 5.4
 *
 * @package Ko
 * @author Nikolay Bondarenko
 * @copyright 2014 Nikolay Bondarenko. All rights reserved.
 * @license MIT http://opensource.org/licenses/MIT
 */
namespace Ko\Worker;

use Ko\AmqpBroker;
use Ko\Process;

/**
 * Class Child
 *
 * @package Ko\Worker
 * @copyright 2015 Nikolay Bondarenko. All rights reserved.
 * @author Nikolay Bondarenko <misterionkell@gmail.com>
 * @version 1.0
 */
class Child
{
    protected $config;

    protected $name;

    protected $executorClass;

    /**
     * @param mixed $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @param mixed $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @param mixed $executorClass
     *
     * @throws \InvalidArgumentException
     */
    public function setExecutorClass($executorClass)
    {
        if (!is_subclass_of($executorClass, '\Ko\Worker\ActionInterface')) {
            throw new \InvalidArgumentException(
                'Executor class ' . $executorClass . ' should implements Ko\Worker\ActionInterface'
            );
        }

        $this->executorClass = $executorClass;
    }

    public function run(Process $process)
    {
        $totalProcessed = 0;
        $this->setProcessTitle($process, 'waiting first envelope');

        $broker = new AmqpBroker($this->config);
        $broker->getConsumer($this->name)->consume(
            function (\AMQPEnvelope $envelope, \AMQPQueue $queue) use ($process, &$totalProcessed) {
                $process->dispatchSignals();

                $this->setProcessTitle($process, 'processing `' . $envelope->getRoutingKey() . '`');

                try {
                    /** @var $executor ActionInterface */
                    $executor = new $this->executorClass();
                    $executor->execute($envelope, $queue);

                    $queue->ack($envelope->getDeliveryTag());
                } catch (\Exception $e) {
                    $queue->nack($envelope->getDeliveryTag(), \AMQP_REQUEUE);
                    throw $e;
                }

                if ($process->isShouldShutdown()) {
                    $this->setProcessTitle($process, 'canceling');
                    $queue->cancel();

                    return false;
                }

                $this->setProcessTitle($process, 'waiting next envelope (processed ' . ++$totalProcessed . ')') ;
                return true;
            }
        );
    }

    /**
     * @param Process $process
     * @param string $title
     *
     * @return string
     */
    protected function setProcessTitle(Process $process, $title)
    {
        $process->setProcessTitle('ko-worker[c|' . $this->name . ']: ' . $title);
    }
} 