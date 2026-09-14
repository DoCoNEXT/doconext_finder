<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\MetadataFields;
use OCA\DcnFinder\Service\CoreScope;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Showing the people a file names, rather than the ids it stores them as.
 *
 * Core keeps a principal on a file as a bare id, and nothing about `admin`
 * says whether it is an account or a group — both exist on most servers. The
 * field it came from knows, so the kind is carried into the question. Anything
 * Core cannot place is shown as stored, and a server without Core shows every
 * value untouched.
 */
class PrincipalColumnNamesTest extends TestCase
{
    /** @var list<list<string>> what was asked of Core, call by call */
    private array $asked = [];

    /**
     * @param array<string, ?string> $principalKeys key => the kind it holds
     * @param array<string, string> $names what Core answers, keyed by the question
     */
    private function fields(array $principalKeys, array $names): MetadataFields
    {
        $core = $this->createMock(CoreScope::class);
        $core->method('principalMetadataKeys')->willReturn($principalKeys);
        $core->method('principalNames')->willReturnCallback(
            function (array $ids) use ($names): array {
                $this->asked[] = $ids;

                return array_intersect_key($names, array_flip($ids));
            },
        );

        return new MetadataFields($this->createMock(IFilesMetadataManager::class), $core);
    }

    /** @param array<string, string> $stored */
    private function fileHolding(array $stored): array
    {
        $metadata = $this->createMock(IFilesMetadata::class);
        $metadata->method('getKeys')->willReturn(array_keys($stored));
        $metadata->method('getType')->willReturn(IMetadataValueWrapper::TYPE_STRING);
        $metadata->method('getString')->willReturnCallback(
            static fn (string $key): string => $stored[$key] ?? '',
        );

        return [7 => $metadata];
    }

    public function testTheKindOfPrincipalIsCarriedIntoTheQuestion(): void
    {
        $fields = $this->fields(
            ['dcn_core_afdeling' => 'group'],
            ['group:admin' => 'Administrators'],
        );

        $values = $fields->values(7, $this->fileHolding(['dcn_core_afdeling' => 'admin']));

        $this->assertSame(['dcn_core_afdeling' => 'Administrators'], $values);
        $this->assertSame([['group:admin']], $this->asked, 'Asked as a group, not as a bare id');
    }

    public function testAFieldTakingMoreThanOneKindIsAskedBare(): void
    {
        $fields = $this->fields(
            ['dcn_core_betrokkenen' => null],
            ['alice' => 'Alice Jansen', 'legal-staff' => 'Legal'],
        );

        $values = $fields->values(7, $this->fileHolding(['dcn_core_betrokkenen' => 'alice, legal-staff']));

        $this->assertSame(['dcn_core_betrokkenen' => 'Alice Jansen, Legal'], $values);
        $this->assertSame([['alice', 'legal-staff']], $this->asked);
    }

    public function testAnIdNobodyAnswersToIsShownAsStored(): void
    {
        $fields = $this->fields(['dcn_core_behandelaar' => 'user'], []);

        $values = $fields->values(7, $this->fileHolding(['dcn_core_behandelaar' => 'ghost']));

        $this->assertSame(['dcn_core_behandelaar' => 'ghost'], $values);
    }

    public function testAnOrdinaryFieldIsLeftAlone(): void
    {
        $fields = $this->fields(['dcn_core_behandelaar' => 'user'], ['user:alice' => 'Alice Jansen']);

        $values = $fields->values(7, $this->fileHolding(['dcn_core_zaaknummer' => 'alice']));

        $this->assertSame(['dcn_core_zaaknummer' => 'alice'], $values);
        $this->assertSame([], $this->asked, 'A value that is not a principal is nobody to ask about');
    }

    public function testTheSamePrincipalIsAskedAboutOncePerRequest(): void
    {
        $fields = $this->fields(['dcn_core_behandelaar' => 'user'], ['user:alice' => 'Alice Jansen']);
        $file   = $this->fileHolding(['dcn_core_behandelaar' => 'alice']);

        $fields->values(7, $file);
        $second = $fields->values(7, $file);

        $this->assertSame(['dcn_core_behandelaar' => 'Alice Jansen'], $second);
        $this->assertSame([['user:alice']], $this->asked, 'A page of results asks once');
    }

    public function testWithoutCoreEveryValueIsShownAsStored(): void
    {
        // No Core: no keys hold principals as far as this app can tell.
        $fields = $this->fields([], []);

        $values = $fields->values(7, $this->fileHolding(['dcn_core_behandelaar' => 'alice']));

        $this->assertSame(['dcn_core_behandelaar' => 'alice'], $values);
    }
}
