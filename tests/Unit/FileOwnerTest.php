<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\FileOwner;
use PHPUnit\Framework\TestCase;

/**
 * Host-pure unit test — no running Nextcloud, just the rule that decides whether
 * an owner is worth reporting.
 */
class FileOwnerTest extends TestCase
{
    public function testHomeMountKeepsItsOwner(): void
    {
        $this->assertSame('alice', FileOwner::of('', 'alice'));
    }

    public function testShareKeepsItsOwner(): void
    {
        // SharedStorage answers with the share's owner, not with the reader.
        $this->assertSame('bob', FileOwner::of('shared', 'bob'));
    }

    public function testFederatedShareKeepsItsRemoteOwner(): void
    {
        // A federated share is mounted as 'shared' too, and reports a cloud id.
        $this->assertSame(
            'carla@example.com',
            FileOwner::of('shared', 'carla@example.com')
        );
    }

    public function testTeamFolderReportsNoOwner(): void
    {
        // Measured: a group mount answers with whoever is asking, so 'alice'
        // here is the reader, not an owner.
        $this->assertSame('', FileOwner::of('group', 'alice'));
    }

    public function testExternalStorageReportsNoOwner(): void
    {
        $this->assertSame('', FileOwner::of('external', 'alice'));
    }

    public function testUnknownMountTypeIsAssumedNotToKnow(): void
    {
        // The list is an allow-list on purpose: a mount type nobody has checked
        // must not get the benefit of the doubt.
        $this->assertSame('', FileOwner::of('something-new', 'alice'));
    }
}
