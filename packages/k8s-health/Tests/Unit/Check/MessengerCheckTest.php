<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Tests\Unit\Check;

use Bmack\K8sHealth\Check\MessengerCheck;
use Bmack\K8sHealth\Check\Status;
use Bmack\K8sHealth\Messenger\WorkerRegistry;
use Bmack\K8sHealth\Messenger\WorkerState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class MessengerCheckTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private MessengerCheck $subject;

    protected function setUp(): void
    {
        $this->subject = new MessengerCheck(
            new WorkerRegistry($this->createStub(ConnectionPool::class)),
            $this->createStub(ConnectionPool::class),
            $this->createStub(ClockInterface::class),
        );
    }

    private static function worker(int $lastSeen, int $stoppedAt = 0, int $failed = 0, string $host = 'typo3-messenger-a'): WorkerState
    {
        return new WorkerState($host, self::NOW - 3600, $lastSeen, $stoppedAt, 12, $failed, $failed > 0 ? 'Foo: broken' : '');
    }

    #[Test]
    public function noWorkerEverSeenIsSkipped(): void
    {
        self::assertSame(Status::Skipped, $this->subject->evaluateWorkers([], self::NOW)->status);
    }

    #[Test]
    public function recentHeartbeatIsRunning(): void
    {
        $result = $this->subject->evaluateWorkers([self::worker(self::NOW - 20)], self::NOW);

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('messenger.workers.running', $result->label);
        self::assertSame([1, 'typo3-messenger-a', 12], $result->arguments);
    }

    #[Test]
    public function failedMessagesAreAWarning(): void
    {
        $result = $this->subject->evaluateWorkers([self::worker(self::NOW - 20, failed: 2)], self::NOW);

        self::assertSame(Status::Warning, $result->status);
        self::assertSame([2, 'Foo: broken'], $result->arguments);
    }

    #[Test]
    public function missingHeartbeatWithoutCleanStopIsAnError(): void
    {
        $result = $this->subject->evaluateWorkers([self::worker(self::NOW - 600)], self::NOW);

        self::assertSame(Status::Error, $result->status);
        self::assertSame('messenger.workers.stale', $result->label);
    }

    #[Test]
    public function cleanStopWithinGraceIsARestart(): void
    {
        $result = $this->subject->evaluateWorkers([self::worker(self::NOW - 10, stoppedAt: self::NOW - 10)], self::NOW);

        self::assertSame(Status::Ok, $result->status);
        self::assertSame('messenger.workers.restarting', $result->label);
    }

    #[Test]
    public function cleanStopLongAgoIsAnError(): void
    {
        $result = $this->subject->evaluateWorkers([self::worker(self::NOW - 900, stoppedAt: self::NOW - 900)], self::NOW);

        self::assertSame(Status::Error, $result->status);
        self::assertSame('messenger.workers.stopped', $result->label);
    }

    #[Test]
    public function oneLiveWorkerOutweighsAStaleOne(): void
    {
        $result = $this->subject->evaluateWorkers(
            [self::worker(self::NOW - 5, host: 'new'), self::worker(self::NOW - 4000, host: 'old')],
            self::NOW
        );

        self::assertSame(Status::Ok, $result->status);
        self::assertSame([1, 'new', 12], $result->arguments);
    }

    #[Test]
    public function queueSeverityFollowsTheAgeOfTheOldestMessage(): void
    {
        self::assertSame(Status::Ok, $this->subject->evaluateQueue(0, 0)->status);
        self::assertSame(Status::Ok, $this->subject->evaluateQueue(3, 60)->status);
        self::assertSame(Status::Warning, $this->subject->evaluateQueue(3, 600)->status);
        self::assertSame(Status::Error, $this->subject->evaluateQueue(3, 3600)->status);
        self::assertSame([3, '10 min'], $this->subject->evaluateQueue(3, 600)->arguments);
    }
}
