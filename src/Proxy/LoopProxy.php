<?php

namespace Kaduev13\EventLoopProfiler\Proxy;

use Kaduev13\EventLoopProfiler\Event\AddPeriodicTimerEvent;
use Kaduev13\EventLoopProfiler\Event\AddReadStreamEvent;
use Kaduev13\EventLoopProfiler\Event\AddTimerEvent;
use Kaduev13\EventLoopProfiler\Event\AddWriteStreamEvent;
use Kaduev13\EventLoopProfiler\Event\CallbackFiredEvent;
use Kaduev13\EventLoopProfiler\Event\CancelTimerEvent;
use Kaduev13\EventLoopProfiler\Event\Event;
use Kaduev13\EventLoopProfiler\Event\FutureTickEvent;
use Kaduev13\EventLoopProfiler\Event\IsTimerActiveEvent;
use Kaduev13\EventLoopProfiler\Event\NextTickEvent;
use Kaduev13\EventLoopProfiler\Event\RemoveReadStreamEvent;
use Kaduev13\EventLoopProfiler\Event\RemoveStreamEvent;
use Kaduev13\EventLoopProfiler\Event\RemoveWriteStreamEvent;
use Kaduev13\EventLoopProfiler\Event\RunEvent;
use Kaduev13\EventLoopProfiler\Event\StopEvent;
use Kaduev13\EventLoopProfiler\Event\TickEvent;
use React\EventLoop\LoopInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class LoopProxy
 *
 * Records all the react-php loop instance activities.
 *
 * @method run()
 * @method addTimer($interval, callable $listener)
 * @method addReadStream($stream, callable $listener)
 * @method addWriteStream($stream, callable $listener)
 * @method removeReadStream($stream)
 * @method removeWriteStream($stream)
 * @method removeStream($stream)
 * @method addPeriodicTimer($interval, callable $listener)
 * @method cancelTimer($timer)
 * @method isTimerActive($timer)
 * @method nextTick(callable $listener)
 * @method futureTick(callable $listener)
 * @method tick()
 * @method stop()
 */
class LoopProxy
{
    const LOOP_METHODS_EVENTS = [
        'addReadStream' => AddReadStreamEvent::class,
        'addWriteStream' => AddWriteStreamEvent::class,
        'removeReadStream' => RemoveReadStreamEvent::class,
        'removeWriteStream' => RemoveWriteStreamEvent::class,
        'removeStream' => RemoveStreamEvent::class,
        'addTimer' => AddTimerEvent::class,
        'addPeriodicTimer' => AddPeriodicTimerEvent::class,
        'cancelTimer' => CancelTimerEvent::class,
        'isTimerActive' => IsTimerActiveEvent::class,
        'nextTick' => NextTickEvent::class,
        'futureTick' => FutureTickEvent::class,
        'tick' => TickEvent::class,
        'run' => RunEvent::class,
        'stop' => StopEvent::class,
    ];

    /**
     * Real loop instance.
     *
     * @var LoopInterface
     */
    private $realLoop;

    /**
     * @var Event[]
     */
    public $events;

    /**
     * The event that we are inside.
     *
     * @var Event
     */
    public $context;

    /**
     * @var EventDispatcher
     */
    public $dispatcher;

    /**
     * LoopProxy constructor.
     *
     * @param LoopInterface $realLoop
     */
    public function __construct(LoopInterface $realLoop)
    {
        $this->realLoop = $realLoop;
        $this->events = [];
        $this->context = null;
        $this->dispatcher = new EventDispatcher();
    }

    /**
     * Calls the $callable and records all the necessary events in given $context.
     *
     * @param $callable
     * @param Event $context
     *
     * @return mixed|null
     */
    public function recordCallbackFired($callable, Event $context)
    {
        $event = new CallbackFiredEvent($callable);
        $event->setContext($context);
        return $this->recordEvent($event, $callable);
    }

    /**
     * Records the event.
     *
     * @param Event $event
     * @param $callable
     *
     * @return mixed|null
     *
     * @throws \Throwable
     */
    private function recordEvent(Event $event, $callable)
    {
        $this->events[] = $event;
        $result = null;
        $currentEvent = $this->context;
        try {
            $event->start();
            $this->dispatcher->dispatch('loop_proxy.event_started', $event);

            $this->context = $event;
            $result = is_callable($callable) ? $callable() : call_user_func_array(...$callable);

            $event->complete($result);
            $this->dispatcher->dispatch('loop_proxy.event_completed', $event);
        } catch (\Throwable $e) {
            $event->fail($e);
            $this->dispatcher->dispatch('loop_proxy.event_failed', $event);
            throw $e;
        } finally {
            $this->context = $currentEvent;
        }

        return $result;
    }

    /**
     * Main proxy method.
     *
     * @param $name
     * @param $arguments
     *
     * @return mixed|null
     */
    public function __call($name, $arguments)
    {
        if (!isset(self::LOOP_METHODS_EVENTS[$name])) {
            throw new \BadMethodCallException($name);
        }

        $className = self::LOOP_METHODS_EVENTS[$name];
        /** @var Event $event */
        $event = new $className(...$arguments);
        if ($this->context) {
            $event->setContext($this->context);
        }
        $profiler = $this;
        for ($i = 0; $i < count($arguments); $i++) {
            if (is_callable($arguments[$i])) {
                $callable = $arguments[$i];
                $arguments[$i] = function () use (&$profiler, $callable, &$event) {
                    return $profiler->recordCallbackFired($callable, $event);
                };
            }
        }

        return $this->recordEvent($event, [[$this->realLoop, $name], $arguments]);
    }
}
