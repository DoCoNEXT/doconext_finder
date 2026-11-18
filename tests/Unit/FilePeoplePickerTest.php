<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Service\FilePeoplePicker;
use OCA\DcnFinder\Service\FileSearchService;
use OCA\DcnFinder\Service\ShareRecipients;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilePeoplePickerTest extends TestCase
{
    private ShareRecipients&MockObject $recipients;
    private IUserManager&MockObject $users;
    private IShareManager&MockObject $sharing;
    private FileSearchService&MockObject $files;
    private FilePeoplePicker $people;

    protected function setUp(): void
    {
        $this->recipients = $this->createMock(ShareRecipients::class);
        $this->users = $this->createMock(IUserManager::class);
        $this->sharing = $this->createMock(IShareManager::class);
        $this->files = $this->createMock(FileSearchService::class);
        $this->sharing->method('allowEnumeration')->willReturn(true);
        $this->people = new FilePeoplePicker($this->recipients, $this->users, $this->sharing, $this->files);
    }

    public function testADisabledAccountIsListedByName(): void
    {
        $this->users->method('getDisabledUsers')->willReturn([$this->user('jdv', 'Jan de Vries', false)]);
        $this->files->method('search')->willReturn(['results' => []]);

        $found = $this->people->search('modified_by', 'jan', $this->user('me', 'Me', true));

        $this->assertSame('Jan de Vries', $found[0]['displayName']);
        $this->assertSame(FilePeoplePicker::STATUS_DISABLED, $found[0]['status']);
    }

    public function testDisabledAccountsStayHiddenWhereTheAdminLimitsWhoCanBeFound(): void
    {
        $this->sharing->method('shareWithGroupMembersOnly')->willReturn(true);
        $this->users->expects($this->never())->method('getDisabledUsers');
        $this->files->method('search')->willReturn(['results' => []]);

        $this->assertSame([], $this->people->search('modified_by', 'jan', $this->user('me', 'Me', true)));
    }

    public function testADeletedAccountIsOfferedOnItsExactIdIfTheSearcherSeesItsFiles(): void
    {
        $this->users->method('getDisabledUsers')->willReturn([]);
        $this->users->method('get')->willReturn(null);
        $this->files->method('search')->willReturn(['results' => [['fileid' => 1]]]);

        $found = $this->people->search('created_by', 'jdv', $this->user('me', 'Me', true));

        $this->assertSame('jdv', $found[0]['id']);
        $this->assertSame(FilePeoplePicker::STATUS_DELETED, $found[0]['status']);
    }

    public function testADeletedAccountWithoutVisibleFilesIsNotRevealed(): void
    {
        $this->users->method('getDisabledUsers')->willReturn([]);
        $this->users->method('get')->willReturn(null);
        $this->files->method('search')->willReturn(['results' => []]);

        $this->assertSame([], $this->people->search('created_by', 'jdv', $this->user('me', 'Me', true)));
    }

    public function testAWorkingAccountCostsNoFileSearch(): void
    {
        $this->users->method('getDisabledUsers')->willReturn([]);
        $this->users->method('get')->willReturn($this->user('jdv', 'Jan', true));
        $this->files->expects($this->never())->method('search');

        $this->assertSame([], $this->people->search('created_by', 'jdv', $this->user('me', 'Me', true)));
    }

    public function testAFailingFileSearchOnlyCostsTheSuggestion(): void
    {
        $this->users->method('getDisabledUsers')->willReturn([]);
        $this->users->method('get')->willReturn(null);
        $this->files->method('search')->willThrowException(new \RuntimeException('no'));

        $this->assertSame([], $this->people->search('created_by', 'jdv', $this->user('me', 'Me', true)));
    }

    private function user(string $uid, string $name, bool $enabled): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($name);
        $user->method('isEnabled')->willReturn($enabled);

        return $user;
    }
}
