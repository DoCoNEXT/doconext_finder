<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\FileCondition;
use PHPUnit\Framework\TestCase;

/**
 * Host-pure unit test — no running Nextcloud, just the value object that guards
 * what reaches the search backend.
 */
class FileConditionTest extends TestCase
{
    public function testOwnerAcceptsEqualityOnly(): void
    {
        $condition = FileCondition::fromArray([
            'field'    => 'owner',
            'operator' => 'eq',
            'value'    => 'alice',
        ]);

        $this->assertSame('owner', $condition->field);
        $this->assertSame('alice', $condition->value);
    }

    public function testOwnerRejectsContains(): void
    {
        // SearchBuilder validates "owner" as equality-only; offering anything
        // else would surface as a 500 from the query builder.
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray([
            'field'    => 'owner',
            'operator' => 'contains',
            'value'    => 'ali',
        ]);
    }

    public function testOwnerCannotBeNegated(): void
    {
        // It is reached through a join, so NOT compares a NULL column.
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray([
            'field'    => 'owner',
            'operator' => 'eq',
            'value'    => 'alice',
            'negate'   => true,
        ]);
    }

    public function testFavoriteOnlyMatchesFavoritedFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray([
            'field'    => 'favorite',
            'operator' => 'eq',
            'value'    => false,
        ]);
    }

    public function testContainsWrapsAndEscapesTheTerm(): void
    {
        $condition = FileCondition::fromArray([
            'field'    => 'name',
            'operator' => 'contains',
            'value'    => '100%_report',
        ]);

        $this->assertSame('%100\%\_report%', $condition->value);
    }
}
