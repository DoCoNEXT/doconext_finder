<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\FileQuery;
use PHPUnit\Framework\TestCase;

/**
 * The ceilings on one search request. The page never comes near them; they
 * exist for the request it did not send, so each is tested right at the edge.
 */
class FileQueryTest extends TestCase
{
    private const CONDITION = ['field' => 'name', 'operator' => 'contains', 'value' => 'invoice'];

    public function testConditionsUpToTheCeilingAreAccepted(): void
    {
        $query = FileQuery::fromArray([
            'conditions' => array_fill(0, FileQuery::MAX_CONDITIONS, self::CONDITION),
        ]);

        $this->assertCount(FileQuery::MAX_CONDITIONS, $query->conditions);
    }

    public function testOneConditionTooManyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileQuery::fromArray([
            'conditions' => array_fill(0, FileQuery::MAX_CONDITIONS + 1, self::CONDITION),
        ]);
    }

    public function testOneMimetypeTooManyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileQuery::fromArray([
            'mimetypes' => array_fill(0, FileQuery::MAX_MIMETYPES + 1, 'application/pdf'),
        ]);
    }

    public function testTermIsMeasuredInCharactersNotBytes(): void
    {
        // Multibyte characters count once each: a Dutch or Greek term at the
        // ceiling must not be refused for its encoding.
        $query = FileQuery::fromArray(['term' => str_repeat('é', FileQuery::MAX_TERM_LENGTH)]);

        $this->assertSame(FileQuery::MAX_TERM_LENGTH, mb_strlen($query->term));
    }

    public function testATermOverTheCeilingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileQuery::fromArray(['term' => str_repeat('a', FileQuery::MAX_TERM_LENGTH + 1)]);
    }

    public function testAContentPhraseOverTheCeilingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileQuery::fromArray(['content' => str_repeat('a', FileQuery::MAX_TERM_LENGTH + 1)]);
    }

    public function testOffsetUpToTheCeilingIsAccepted(): void
    {
        $query = FileQuery::fromArray(['term' => 'x', 'offset' => FileQuery::MAX_OFFSET]);

        $this->assertSame(FileQuery::MAX_OFFSET, $query->offset);
    }

    public function testAnOffsetBeyondTheCeilingIsRefusedNotClamped(): void
    {
        // Clamping would hand a pager the same page back for every "next",
        // so the request is refused instead.
        $this->expectException(\InvalidArgumentException::class);

        FileQuery::fromArray(['term' => 'x', 'offset' => FileQuery::MAX_OFFSET + 1]);
    }

    public function testANegativeOffsetStartsAtTheTop(): void
    {
        $this->assertSame(0, FileQuery::fromArray(['term' => 'x', 'offset' => -5])->offset);
    }
}
