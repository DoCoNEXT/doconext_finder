<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\MetadataFields;
use OCA\DcnFinder\Service\ContentSearchService;
use OCA\DcnFinder\Service\CoreScope;
use OCA\DcnFinder\Service\FileAuthorService;
use OCA\DcnFinder\Service\FileSearchService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IDBConnection;
use OCP\ITagManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Which files a request can touch.
 *
 * The access check in this service is not a check at all but a lookup: a file
 * id is resolved through the caller's own home folder, and what that folder
 * cannot reach does not exist. These tests pin the two paths where an id comes
 * from the request — a favorite toggle, and a search scope — so a refactor
 * that resolved through the root folder instead would fail here.
 *
 * Only the paths that end before a query is built are covered: building one
 * needs core's private search classes, which the unit suite runs without
 * (see tests/Platform for those).
 */
class FileSearchServiceAccessTest extends TestCase
{
    private Folder $userFolder;
    private ITagManager $tagManager;
    private CoreScope $coreScope;

    protected function setUp(): void
    {
        $this->userFolder = $this->createMock(Folder::class);
        $this->tagManager = $this->createMock(ITagManager::class);
        $this->coreScope = $this->createMock(CoreScope::class);
    }

    private function service(): FileSearchService
    {
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('alice')->willReturn($this->userFolder);

        return new FileSearchService(
            $root,
            $this->createMock(IUserManager::class),
            $this->tagManager,
            $this->createMock(IFilesMetadataManager::class),
            $this->createMock(MetadataFields::class),
            $this->createMock(FileAuthorService::class),
            $this->coreScope,
            $this->createMock(ContentSearchService::class),
            $this->createMock(IDBConnection::class),
        );
    }

    public function testAFavoriteOnAFileOutsideTheCallersTreeIsNotFound(): void
    {
        $this->userFolder->method('getFirstNodeById')->with(42)->willReturn(null);
        // The tag store is never opened: nothing is written for a file the
        // caller cannot see, not even a removal.
        $this->tagManager->expects($this->never())->method('load');

        $this->expectException(NotFoundException::class);

        $this->service()->setFavorite('alice', 42, true);
    }

    public function testAScopeTheCallerCannotReachIsAnEmptyResultNotTheWholeAccount(): void
    {
        $this->coreScope->method('roots')->willReturn(['roots' => [99], 'truncated' => false]);
        $this->userFolder->method('getFirstNodeById')->with(99)->willReturn(null);
        // Falling back to the home folder would turn "a folder I may not see"
        // into "everything I may see" — the search must not run at all.
        $this->userFolder->expects($this->never())->method('search');

        $page = $this->service()->search('alice', FileQuery::fromArray([
            'term'  => 'contract',
            'scope' => ['level' => 'folder', 'id' => 99],
        ]));

        $this->assertSame([], $page['results']);
        $this->assertFalse($page['hasMore']);
    }

    public function testAScopeRootThatIsAFileRatherThanAFolderIsDropped(): void
    {
        $this->coreScope->method('roots')->willReturn(['roots' => [5], 'truncated' => false]);
        $this->userFolder->method('getFirstNodeById')->with(5)->willReturn($this->createMock(File::class));
        $this->userFolder->expects($this->never())->method('search');

        $page = $this->service()->search('alice', FileQuery::fromArray([
            'term'  => 'contract',
            'scope' => ['level' => 'folder', 'id' => 5],
        ]));

        $this->assertSame([], $page['results']);
    }
}
