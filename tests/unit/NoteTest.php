<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Db\Note;
use PHPUnit\Framework\TestCase;

/**
 * Example host-pure unit test — no running Nextcloud, just the entity. Model
 * your Service tests on this (mock the Mapper + ITimeFactory).
 */
class NoteTest extends TestCase
{
    public function testToArrayExposesCamelCaseFields(): void
    {
        $note = new Note();
        $note->setTitle('Hello');
        $note->setContent('World');
        $note->setCreatedAt('2026-01-01 00:00:00');

        $arr = $note->toArray();

        $this->assertSame('Hello', $arr['title']);
        $this->assertSame('World', $arr['content']);
        $this->assertArrayHasKey('createdAt', $arr);
        $this->assertArrayNotHasKey('userId', $arr, 'internal owner column must not leak to the API');
    }
}
