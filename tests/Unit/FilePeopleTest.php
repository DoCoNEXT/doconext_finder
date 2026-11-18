<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\BackgroundJob\RecordCopiedFolder;
use OCA\DcnFinder\Listener\FilePeopleListener;
use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\PersonField;
use OCA\DcnFinder\Service\FilePeopleIndex;
use OCA\DcnFinder\Service\ShareRecipients;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountPoint;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Teams\ITeamManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FilePeopleTest extends TestCase
{
    public function testTheTokenOfTheLongestAccountIdFitsTheIndexColumn(): void
    {
        $this->assertLessThanOrEqual(63, strlen(PersonField::token(str_repeat('a', 64))));
    }

    public function testTheMetadataKeysFitTheirColumn(): void
    {
        foreach (PersonField::INTERNAL_KEYS as $key) {
            $this->assertLessThanOrEqual(31, strlen($key));
        }
    }

    public function testAFileKeepsItsFirstMaker(): void
    {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturn(true);
        $metadata->expects($this->never())->method('setString');

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturn($metadata);

        (new FilePeopleIndex($manager, $this->createMock(LoggerInterface::class)))->recordCreator(1, 'bob');
    }

    public function testTheMakerIsStoredHashedAndReadable(): void
    {
        $stored = [];
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturn(false);
        $metadata->method('setString')->willReturnCallback(function (string $key, string $value) use (&$stored, $metadata) {
            $stored[$key] = $value;

            return $metadata;
        });

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturn($metadata);

        (new FilePeopleIndex($manager, $this->createMock(LoggerInterface::class)))->recordCreator(1, 'bob');

        $this->assertSame(PersonField::token('bob'), $stored[PersonField::CREATED_BY_KEY]);
        $this->assertSame('bob', $stored[PersonField::CREATOR_ID_KEY]);
    }

    public function testANewFileRecordsItsMaker(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->expects($this->once())->method('recordCreator')->with(7, 'alice');
        $index->expects($this->never())->method('recordEditor');

        $this->listener($index, 'alice')->handle(new NodeCreatedEvent($this->file('', 'owner')));
    }

    public function testACopyIsMadeByWhoeverCopied(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->expects($this->once())->method('recordCreator')->with(7, 'alice');

        $this->listener($index, 'alice')->handle(new NodeCopiedEvent($this->createMock(File::class), $this->file('', 'owner')));
    }

    public function testACopiedFolderLeavesItsFilesToABackgroundJob(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getId')->willReturn(9);

        $jobs = $this->createMock(IJobList::class);
        $jobs->expects($this->once())->method('add')
            ->with(RecordCopiedFolder::class, ['folderId' => 9, 'uid' => 'alice']);

        $this->listener($this->createMock(FilePeopleIndex::class), 'alice', $jobs)
            ->handle(new NodeCopiedEvent($this->createMock(Folder::class), $folder));
    }

    public function testANewFolderStartsNoJob(): void
    {
        $jobs = $this->createMock(IJobList::class);
        $jobs->expects($this->never())->method('add');

        $this->listener($this->createMock(FilePeopleIndex::class), 'alice', $jobs)
            ->handle(new NodeCreatedEvent($this->createMock(Folder::class)));
    }

    public function testAMoveBetweenStoragesKeepsTheMakerAndTheEditor(): void
    {
        $values = [
            PersonField::MODIFIED_BY_KEY => 'hash-editor',
            PersonField::CREATED_BY_KEY => 'hash-maker',
            PersonField::CREATOR_ID_KEY => 'alice',
        ];
        $before = $this->createMock(IFilesMetadata::class);
        $before->method('hasKey')->willReturnCallback(fn (string $key) => isset($values[$key]));
        $before->method('getString')->willReturnCallback(fn (string $key) => $values[$key]);

        $restored = [];
        $after = $this->createMock(IFilesMetadata::class);
        $after->method('hasKey')->willReturn(false);
        $after->method('setString')->willReturnCallback(function (string $key, string $value, bool $indexed) use (&$restored, $after) {
            $restored[$key] = [$value, $indexed];

            return $after;
        });

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturnOnConsecutiveCalls($before, $after);

        $index = new FilePeopleIndex($manager, $this->createMock(LoggerInterface::class));
        $index->rememberBeforeMove(1);
        $index->restoreAfterMove(1);

        $this->assertSame(['hash-maker', true], $restored[PersonField::CREATED_BY_KEY]);
        $this->assertSame(['alice', false], $restored[PersonField::CREATOR_ID_KEY]);
    }

    public function testCreatedByIsAFilterForOnePerson(): void
    {
        $condition = FileCondition::fromArray(['field' => 'created_by', 'operator' => 'eq', 'value' => 'alice']);

        $this->assertSame('alice', $condition->value);
    }

    public function testNotCreatedByIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray(['field' => 'created_by', 'operator' => 'eq', 'value' => 'alice', 'negate' => true]);
    }

    public function testTheFilterAsksForOnePerson(): void
    {
        $condition = FileCondition::fromArray(['field' => 'modified_by', 'operator' => 'eq', 'value' => 'alice']);

        $this->assertSame('alice', $condition->value);
    }

    public function testPartOfANameIsNotAsked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray(['field' => 'modified_by', 'operator' => 'contains', 'value' => 'ali']);
    }

    public function testNotModifiedByIsRefused(): void
    {
        // It would silently leave out every file with no name on record.
        $this->expectException(\InvalidArgumentException::class);

        FileCondition::fromArray(['field' => 'modified_by', 'operator' => 'eq', 'value' => 'alice', 'negate' => true]);
    }

    public function testBackfillLeavesAFileThatAlreadyHasAnEntry(): void
    {
        $known = $this->createMock(IFilesMetadata::class);
        $known->method('hasKey')->willReturn(true);
        $known->expects($this->never())->method('setString');

        $fresh = $this->createMock(IFilesMetadata::class);
        $fresh->method('hasKey')->willReturn(false);
        $fresh->expects($this->once())->method('setString')
            ->with(PersonField::MODIFIED_BY_KEY, PersonField::token('bob'), true);

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadataForFiles')->willReturn([1 => $known]);
        $manager->method('getMetadata')->with(2, true)->willReturn($fresh);
        $manager->expects($this->once())->method('saveMetadata')->with($fresh);

        (new FilePeopleIndex($manager, $this->createMock(LoggerInterface::class)))
            ->recordMissingEditors([1 => 'alice', 2 => 'bob']);
    }

    public function testClearingEmptiesTheEntrySoTheOldNameStopsMatching(): void
    {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('hasKey')->willReturn(true);
        $metadata->expects($this->once())->method('setString')->with(PersonField::MODIFIED_BY_KEY, '', true);

        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willReturn($metadata);

        (new FilePeopleIndex($manager, $this->createMock(LoggerInterface::class)))->clearEditor(1);
    }

    public function testClearingAFileWithoutMetadataDoesNothing(): void
    {
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getMetadata')->willThrowException(new FilesMetadataNotFoundException());
        $manager->expects($this->never())->method('saveMetadata');

        (new FilePeopleIndex($manager, $this->createMock(LoggerInterface::class)))->clearEditor(1);
    }

    public function testAWriteIsRecordedForWhoeverIsSignedIn(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->expects($this->once())->method('recordEditor')->with(7, 'alice');

        $this->listener($index, 'alice')->handle(new NodeWrittenEvent($this->file('', 'owner')));
    }

    public function testAnAnonymousWriteToAPersonalFolderCountsAsItsOwner(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->expects($this->once())->method('recordEditor')->with(7, 'owner');

        $this->listener($index, null)->handle(new NodeWrittenEvent($this->file('', 'owner')));
    }

    public function testAnAnonymousWriteToATeamFolderLeavesNoName(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->expects($this->never())->method('recordEditor');
        $index->expects($this->once())->method('clearEditor')->with(7);

        $this->listener($index, null)->handle(new NodeWrittenEvent($this->file('group', 'whoever')));
    }

    public function testAFolderIsSkipped(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->expects($this->never())->method('recordEditor');

        $this->listener($index, 'alice')->handle(new NodeWrittenEvent($this->createMock(Folder::class)));
    }

    public function testAFailureNeverReachesTheUpload(): void
    {
        $index = $this->createMock(FilePeopleIndex::class);
        $index->method('recordEditor')->willThrowException(new \RuntimeException('database gone'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $session = $this->session('alice');
        (new FilePeopleListener($index, $session, $this->createMock(IJobList::class), $logger))->handle(new NodeWrittenEvent($this->file('', 'owner')));
    }

    public function testYouCanPickYourselfThoughTheShareSearchLeavesYouOut(): void
    {
        $me = $this->user('admin');
        $me->method('getDisplayName')->willReturn('The Admin');

        $this->assertSame(
            [['type' => 'user', 'id' => 'admin', 'displayName' => 'The Admin', 'subname' => 'admin']],
            $this->recipients()->searchUsers('ADM', $me),
        );
    }

    public function testYouAreNotOfferedForATermThatDoesNotFitYou(): void
    {
        $me = $this->user('admin');
        $me->method('getDisplayName')->willReturn('The Admin');

        $this->assertSame([], $this->recipients()->searchUsers('bob', $me));
    }

    private function recipients(): ShareRecipients
    {
        $search = $this->createMock(ISearch::class);
        $method = method_exists(ISearch::class, 'filteredSearch') ? 'filteredSearch' : 'search';
        $search->method($method)->willReturn([[], false]);

        return new ShareRecipients(
            $search,
            $this->createMock(IUserManager::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IShareManager::class),
            $this->createMock(ITeamManager::class),
            $this->createMock(IAppManager::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function listener(FilePeopleIndex $index, ?string $uid, ?IJobList $jobs = null): FilePeopleListener
    {
        return new FilePeopleListener($index, $this->session($uid), $jobs ?? $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class));
    }

    private function session(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($uid === null ? null : $this->user($uid));

        return $session;
    }

    private function user(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function file(string $mountType, string $owner): File
    {
        $mount = $this->createMock(IMountPoint::class);
        $mount->method('getMountType')->willReturn($mountType);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(7);
        $file->method('getMountPoint')->willReturn($mount);
        $file->method('getOwner')->willReturn($this->user($owner));

        return $file;
    }
}
