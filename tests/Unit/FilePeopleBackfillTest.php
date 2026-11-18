<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Service\FileAuthorService;
use OCA\DcnFinder\Service\FilePeopleBackfill;
use OCA\DcnFinder\Service\FilePeopleIndex;
use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilePeopleBackfillTest extends TestCase
{
    public function testNothingHappensUntilAnAdminStartsIt(): void
    {
        $backfill = $this->backfill(['step' => 0, 'after' => 0, 'running' => false]);

        $this->assertFalse($backfill->isRunning());
        $this->assertFalse($backfill->isComplete());
        $this->assertSame('idle', $backfill->status()['state']);
    }

    public function testAStartedRunIsRunning(): void
    {
        $backfill = $this->backfill(['step' => 2, 'after' => 10, 'running' => true]);

        $this->assertTrue($backfill->isRunning());
        $this->assertSame('running', $backfill->status()['state']);
    }

    public function testAStoppedRunWithProgressIsPaused(): void
    {
        $this->assertSame('paused', $this->backfill(['step' => 0, 'after' => 10, 'running' => false])->status()['state']);
    }

    public function testAFinishedRunIsDoneWhateverTheFlagSays(): void
    {
        $backfill = $this->backfill(['step' => 6, 'after' => 0, 'running' => true]);

        $this->assertFalse($backfill->isRunning());
        $this->assertTrue($backfill->isComplete());
        $this->assertSame(['state' => 'done', 'percent' => 100], $backfill->status());
    }

    public function testProgressFollowsTheSizeOfWhatIsLeft(): void
    {
        // Revisions 100 + 0, files 900, twice over: 2000 in all.
        $this->assertSame(0, FilePeopleBackfill::percent(0, 0, [100, 0, 900]));
        $this->assertSame(27, FilePeopleBackfill::percent(2, 450, [100, 0, 900]));
        $this->assertSame(50, FilePeopleBackfill::percent(3, 0, [100, 0, 900]));
    }

    public function testProgressNeverClaimsToBeFinishedEarly(): void
    {
        $this->assertSame(99, FilePeopleBackfill::percent(5, 900, [100, 0, 900]));
        $this->assertSame(0, FilePeopleBackfill::percent(0, 0, [0, 0, 0]));
    }

    public function testTheCommandHoldsOffTheJobAndLetsItResumeIfWorkIsLeft(): void
    {
        $backfill = $this->foreground(running: true, complete: false);
        $backfill->method('advance')->willReturn(false);
        $backfill->expects($this->once())->method('pause');
        $backfill->expects($this->once())->method('start');

        $this->assertFalse($backfill->runInForeground(null, static function (): void {
        }));
    }

    public function testTheCommandDoesNotStartAJobThatWasNotRunning(): void
    {
        $backfill = $this->foreground(running: false, complete: false);
        $backfill->method('advance')->willReturn(false);
        $backfill->expects($this->never())->method('start');

        $backfill->runInForeground(null, static function (): void {
        });
    }

    public function testTheCommandDoesNotResumeWhatItFinished(): void
    {
        $backfill = $this->foreground(running: true, complete: true);
        $backfill->expects($this->exactly(2))->method('advance')->willReturnOnConsecutiveCalls(true, false);
        $backfill->expects($this->never())->method('start');

        $this->assertTrue($backfill->runInForeground(null, static function (): void {
        }));
    }

    public function testTheCommandStopsWhenItsTimeIsUp(): void
    {
        $backfill = $this->foreground(running: false, complete: false);
        $backfill->expects($this->never())->method('advance');

        $this->assertFalse($backfill->runInForeground(0, static function (): void {
        }));
    }

    private function foreground(bool $running, bool $complete): FilePeopleBackfill&MockObject
    {
        $backfill = $this->getMockBuilder(FilePeopleBackfill::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['advance', 'pause', 'start', 'isRunning', 'isComplete'])
            ->getMock();
        $backfill->method('isRunning')->willReturn($running);
        $backfill->method('isComplete')->willReturn($complete);

        return $backfill;
    }

    /** @param array{step: int, after: int, running: bool} $state */
    private function backfill(array $state): FilePeopleBackfill
    {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key): int => str_ends_with($key, '_step') ? $state['step'] : $state['after'],
        );
        $config->method('getValueBool')->willReturn($state['running']);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no database here'));

        return new FilePeopleBackfill(
            $db,
            $config,
            $this->createMock(IMimeTypeLoader::class),
            $this->createMock(FileAuthorService::class),
            $this->createMock(FilePeopleIndex::class),
        );
    }
}
