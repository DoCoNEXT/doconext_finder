<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Platform;

use OCA\DcnFinder\Search\InvalidQueryException;
use OCA\DcnFinder\Search\MetadataDay;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use PHPUnit\Framework\TestCase;

/**
 * A day asked of a key that holds a moment is a range of UTC instants between
 * two of the searcher's midnights; of a key that holds a date, the date.
 */
class MetadataDayTest extends TestCase
{
    private const KEY = 'dcn_core_email_sent_at';

    protected function setUp(): void
    {
        if (!class_exists(\OC\Files\Search\SearchComparison::class)) {
            $this->fail('OC\Files\Search is not loadable: set NEXTCLOUD_SERVER_DIR to a Nextcloud server checkout.');
        }
    }

    /**
     * Do:     ask for mail sent on 24 June 2026, searching from Amsterdam.
     * Expect: from 22:00 UTC on the 23rd up to 22:00 UTC on the 24th.
     * Why:    a mail sent at 01:00 Amsterdam time is stored as 23:00 UTC the
     *         day before, and a text match on "2026-06-24" would miss it.
     */
    public function testADayOfAMomentIsTheSearchersDay(): void
    {
        $this->assertSame(
            ['and', ['gte', '2026-06-23T22:00:00+00:00'], ['lt', '2026-06-24T22:00:00+00:00']],
            self::shape(MetadataDay::operator(self::KEY, 'datetime', 'eq', '2026-06-24', false, self::amsterdam())),
        );
    }

    /**
     * Do:     ask for 25 October 2026, the day the clocks go back.
     * Expect: a range of 25 hours.
     */
    public function testTheDayTheClockChangesIsTwentyFiveHours(): void
    {
        $this->assertSame(
            ['and', ['gte', '2026-10-24T22:00:00+00:00'], ['lt', '2026-10-25T23:00:00+00:00']],
            self::shape(MetadataDay::operator(self::KEY, 'datetime', 'eq', '2026-10-25', false, self::amsterdam())),
        );
    }

    /**
     * Do:     ask before, on or before, after, and on or after a day.
     * Expect: every bound the start of a day; "on or before" up to the next one.
     */
    public function testEachBoundIsTheStartOfADay(): void
    {
        $at = fn (string $op): array => self::shape(MetadataDay::operator(self::KEY, 'datetime', $op, '2026-06-24', false, self::amsterdam()));

        $this->assertSame(['lt', '2026-06-23T22:00:00+00:00'], $at('lt'));
        $this->assertSame(['lt', '2026-06-24T22:00:00+00:00'], $at('lte'));
        $this->assertSame(['gte', '2026-06-24T22:00:00+00:00'], $at('gt'));
        $this->assertSame(['gte', '2026-06-23T22:00:00+00:00'], $at('gte'));
    }

    /**
     * Do:     ask "not on" a day.
     * Expect: before it or from the next one, not NOT(AND(...)).
     * Why:    Nextcloud's search takes only a plain comparison inside "not".
     */
    public function testNotOnADayIsBeforeItOrAfterIt(): void
    {
        $this->assertSame(
            ['or', ['lt', '2026-06-23T22:00:00+00:00'], ['gte', '2026-06-24T22:00:00+00:00']],
            self::shape(MetadataDay::operator(self::KEY, 'datetime', 'eq', '2026-06-24', true, self::amsterdam())),
        );
    }

    /**
     * Do:     ask a date field for a day, and for up to that day.
     * Expect: the date itself; the next date as the bound.
     * Why:    a date is the same day on every clock.
     */
    public function testADateIsComparedAsTheDateItself(): void
    {
        $this->assertSame(['eq', '2026-06-24'], self::shape(MetadataDay::operator('dcn_core_deadline', 'date', 'eq', '2026-06-24', false, self::amsterdam())));
        $this->assertSame(['lt', '2026-06-25'], self::shape(MetadataDay::operator('dcn_core_deadline', 'date', 'lte', '2026-06-24', false, new \DateTimeZone('Pacific/Auckland'))));
    }

    public function testWhatIsNoDayIsRefused(): void
    {
        $this->expectException(InvalidQueryException::class);
        MetadataDay::operator(self::KEY, 'datetime', 'eq', '24-06-2026', false, self::amsterdam());
    }

    /** @return array<int, mixed> */
    private static function shape(ISearchOperator $operator): array
    {
        if ($operator instanceof ISearchBinaryOperator) {
            return [$operator->getType(), ...array_map(self::shape(...), $operator->getArguments())];
        }
        if ($operator instanceof ISearchComparison) {
            return [$operator->getType(), $operator->getValue()];
        }
        return [];
    }

    private static function amsterdam(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Amsterdam');
    }
}
