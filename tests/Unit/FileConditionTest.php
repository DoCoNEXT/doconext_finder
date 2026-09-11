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
    public function testCreatedByIsNotAFilter(): void
    {
        // Core's `owner` field matches through the share table: once per share,
        // and never a file its owner did not share.
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray([
            'field'    => 'owner',
            'operator' => 'eq',
            'value'    => 'alice',
        ]);
    }

    public function testFolderPathIsNotAFilter(): void
    {
        // The folder scope asks the same question.
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray([
            'field'    => 'path',
            'operator' => 'contains',
            'value'    => 'Legal',
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
