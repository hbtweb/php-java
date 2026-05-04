<?php
declare(strict_types=1);
namespace PHPJava\Aot\Runtime\java\util\concurrent;

/**
 * Auto-generated JDK signature stub. All members throw
 * NotImplementedException — Path C of docs/LAYERS.md §License posture.
 *
 * Source: javap signature of java.util.concurrent.ForkJoinPool. Regenerate via
 *   php tools/gen-aot-stubs.php java.util.concurrent.ForkJoinPool
 */
class ForkJoinPool
{
    public static $DEFAULT_KEEPALIVE = null;
    public static $TIMEOUT_SLOP = null;
    public static $DEFAULT_COMMON_MAX_SPARES = null;
    public static $INITIAL_QUEUE_CAPACITY = null;
    public static $INITIAL_EXTERNAL_QUEUE_CAPACITY = null;
    public static $SMASK = null;
    public static $LMASK = null;
    public static $UMASK = null;
    public static $MAX_CAP = null;
    public static $EXTERNAL_ID_MASK = null;
    public static $INVALID_ID = null;
    public static $STOP = null;
    public static $SHUTDOWN = null;
    public static $CLEANED = null;
    public static $TERMINATED = null;
    public static $RS_LOCK = null;
    public static $SPIN_WAITS = null;
    public static $MIN_SLEEP = null;
    public static $MAX_SLEEP = null;
    public static $FIFO = null;
    public static $CLEAR_TLS = null;
    public static $PRESET_SIZE = null;
    public static $DROPPED = null;
    public static $UNCOMPENSATE = null;
    public static $IDLE = null;
    public static $MIN_QUEUES_SIZE = null;
    public static $RC_SHIFT = null;
    public static $RC_UNIT = null;
    public static $RC_MASK = null;
    public static $TC_SHIFT = null;
    public static $TC_UNIT = null;
    public static $TC_MASK = null;
    public static $ABASE = null;
    public static $ASHIFT = null;
    public static $defaultForkJoinWorkerThreadFactory = null;
    public static $common = null;
    public $termination = null;
    public $saturate = null;
    public $factory = null;
    public $ueh = null;
    public $container = null;
    public $workerNamePrefix = null;
    public $poolName = null;
    public $delayScheduler = null;
    public $queues = null;
    public $runState = null;
    public $keepAlive = null;
    public $config = null;
    public $stealCount = null;
    public $threadIds = null;
    public $ctl = null;
    public $parallelism = null;

    public static function slotOffset($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function poolIsStopping($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function nextWorkerThreadName()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function registerWorker($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function deregisterWorker($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function signalWork()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function runWorker($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function uncompensate()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function helpJoin($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function helpComplete($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function helpQuiescePool($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function nextTaskFor($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function externalSubmissionQueue($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function externalQueue($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function commonQueue()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function helpAsyncBlocker($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function getSurplusQueuedTaskCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function __construct($a0 = null, $a1 = null, $a2 = null, $a3 = null, $a4 = null, $a5 = null, $a6 = null, $a7 = null, $a8 = null, $a9 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function commonPool()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function asyncCommonPool()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function invoke($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function execute($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function submit($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function externalSubmit($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function lazySubmit($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function setParallelism($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function invokeAllUninterruptibly($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function invokeAll($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function invokeAny($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function shutdownStatus($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function tryStopIfShutdown($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function executeEnabledScheduledTask($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function scheduleDelayedTask($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function schedule($a0 = null, $a1 = null, $a2 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function scheduleAtFixedRate($a0 = null, $a1 = null, $a2 = null, $a3 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function scheduleWithFixedDelay($a0 = null, $a1 = null, $a2 = null, $a3 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function submitWithTimeout($a0 = null, $a1 = null, $a2 = null, $a3 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function cancelDelayedTasksOnShutdown()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getFactory()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getUncaughtExceptionHandler()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getParallelism()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function getCommonPoolParallelism()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getPoolSize()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getAsyncMode()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getRunningThreadCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getActiveThreadCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function isQuiescent()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getStealCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getQueuedTaskCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getQueuedSubmissionCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function getDelayedTaskCount()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function hasQueuedSubmissions()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function pollSubmission()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function drainTasksTo($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function toString()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function shutdown()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function shutdownNow()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function isTerminated()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function isTerminating()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function isShutdown()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function awaitTermination($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function awaitQuiescence($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function close()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public static function managedBlock($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function beginCompensatedBlock()
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function endCompensatedBlock($a0 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }

    public function newTaskFor($a0 = null, $a1 = null)
    {
        throw new \PHPJava\Exceptions\NotImplementedException(__METHOD__);
    }
}
