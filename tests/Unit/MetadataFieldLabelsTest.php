<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\MetadataFields;
use OCA\DcnFinder\Service\CoreScope;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Naming a metadata field the way the app that wrote it does.
 *
 * A key is how a value is stored, not what anyone calls it: an English
 * workspace whose field was keyed in Dutch labels it "Matter Number", and a
 * column headed "Dossiernummer" is a column nobody there recognises. Core says
 * the label per workspace; where its workspaces disagree, or there is no Core,
 * the key still names itself.
 */
class MetadataFieldLabelsTest extends TestCase
{
    /**
     * @param array<string, array<int, string>> $labels what Core answers
     * @return array<string, array{label: string, labels: array<int, string>}>
     */
    private function fieldsLabelled(array $labels): array
    {
        $known = $this->createMock(IFilesMetadata::class);
        $known->method('getKeys')->willReturn(['dcn_core_dossiernummer', 'dcn_core_document_type', 'other_app_key']);
        $known->method('getIndexes')->willReturn([]);
        $known->method('getType')->willReturn('string');
        $manager = $this->createMock(IFilesMetadataManager::class);
        $manager->method('getKnownMetadata')->willReturn($known);
        $core = $this->createMock(CoreScope::class);
        $core->method('principalFields')->willReturn([]);
        $core->method('fieldLabels')->willReturn($labels);

        $byKey = [];
        foreach ((new MetadataFields($manager, $core))->all() as $field) {
            $byKey[$field['key']] = ['label' => $field['label'], 'labels' => $field['labels']];
        }

        return $byKey;
    }

    public function testCoresLabelReplacesTheKey(): void
    {
        $fields = $this->fieldsLabelled(['dcn_core_dossiernummer' => [1 => 'Matter Number']]);

        $this->assertSame('Matter Number', $fields['dcn_core_dossiernummer']['label']);
        $this->assertSame([1 => 'Matter Number'], $fields['dcn_core_dossiernummer']['labels']);
    }

    public function testWorkspacesThatDisagreeLeaveTheKeyToNameItself(): void
    {
        $fields = $this->fieldsLabelled(['dcn_core_document_type' => [1 => 'Type of record', 3 => 'Kind of document']]);

        $this->assertSame('Document type', $fields['dcn_core_document_type']['label']);
        $this->assertSame([1 => 'Type of record', 3 => 'Kind of document'], $fields['dcn_core_document_type']['labels']);
    }

    public function testWithoutCoreEveryKeyNamesItself(): void
    {
        $fields = $this->fieldsLabelled([]);

        $this->assertSame('Dossiernummer', $fields['dcn_core_dossiernummer']['label']);
        $this->assertSame('Other app key', $fields['other_app_key']['label']);
        $this->assertSame([], $fields['other_app_key']['labels']);
    }
}
