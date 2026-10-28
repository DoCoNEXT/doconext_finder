<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Db\SearchSchedule;
use OCA\DcnFinder\Service\ScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * When a schedule is next due. Worked out on the user's own calendar, so these
 * read their expectations as local times in Amsterdam.
 */
class ScheduleNextRunTest extends TestCase
{
    private \DateTimeZone $zone;

    protected function setUp(): void
    {
        $this->zone = new \DateTimeZone('Europe/Amsterdam');
    }

    public function testDailyLaterTheSameDay(): void
    {
        $this->assertNext('2026-10-28 08:00', SearchSchedule::FREQUENCY_DAILY, 0, '08:00', '2026-10-28 07:59');
    }

    public function testDailyAtTheMomentItselfIsTomorrow(): void
    {
        // The run that is due now has just happened; the next is a day on.
        $this->assertNext('2026-10-29 08:00', SearchSchedule::FREQUENCY_DAILY, 0, '08:00', '2026-10-28 08:00');
    }

    public function testWeekdaysSkipTheWeekend(): void
    {
        // Friday 30 October, after eight: next is Monday.
        $this->assertNext('2026-11-02 08:00', SearchSchedule::FREQUENCY_WEEKDAYS, 0, '08:00', '2026-10-30 09:00');
    }

    public function testWeeklyOnItsOwnDay(): void
    {
        // Wednesday; Friday is ISO day 5.
        $this->assertNext('2026-10-30 16:00', SearchSchedule::FREQUENCY_WEEKLY, 5, '16:00', '2026-10-28 12:00');
    }

    public function testWeeklyJustAfterItsMomentIsAWeekOn(): void
    {
        $this->assertNext('2026-11-06 16:00', SearchSchedule::FREQUENCY_WEEKLY, 5, '16:00', '2026-10-30 16:01');
    }

    public function testEightStaysEightAcrossTheEndOfSummerTime(): void
    {
        // Clocks went back on Sunday 25 October 2026: 23 hours of UTC between
        // the two mornings would be wrong; it is 25.
        $after = $this->local('2026-10-24 08:00');
        $next = ScheduleService::nextRun(SearchSchedule::FREQUENCY_DAILY, 0, '08:00', $this->zone, $after);

        $this->assertSame($this->local('2026-10-25 08:00'), $next);
        $this->assertSame(25 * 3600, $next - $after);
    }

    public function testEightStaysEightAcrossTheStartOfSummerTime(): void
    {
        $this->assertNext('2027-03-28 08:00', SearchSchedule::FREQUENCY_DAILY, 0, '08:00', '2027-03-27 08:00');
    }

    public function testTheUsersZoneDecidesTheDay(): void
    {
        // 23:30 UTC on Friday is already Saturday in Amsterdam.
        $after = (new \DateTimeImmutable('2026-10-30 23:30', new \DateTimeZone('UTC')))->getTimestamp();
        $next = ScheduleService::nextRun(SearchSchedule::FREQUENCY_WEEKDAYS, 0, '08:00', $this->zone, $after);

        $this->assertSame($this->local('2026-11-02 08:00'), $next);
    }

    private function assertNext(string $expected, string $frequency, int $weekday, string $time, string $after): void
    {
        $next = ScheduleService::nextRun($frequency, $weekday, $time, $this->zone, $this->local($after));

        $this->assertSame(
            $expected,
            (new \DateTimeImmutable('@' . $next))->setTimezone($this->zone)->format('Y-m-d H:i'),
        );
    }

    private function local(string $time): int
    {
        return (new \DateTimeImmutable($time, $this->zone))->getTimestamp();
    }
}
