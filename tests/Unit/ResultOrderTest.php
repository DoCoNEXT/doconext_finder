<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use LogicException;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\ResultOrder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchOrder;
use OCP\FilesMetadata\IMetadataQuery;
use PHPUnit\Framework\TestCase;

/**
 * Host-pure unit test — the PHP ordering that has to agree with the ORDER BY
 * a search was cut by.
 */
class ResultOrderTest extends TestCase
{
    public function testCreationTimeNewestFirst(): void
    {
        // The sort Folder::search() leaves in file-id order across storages.
        $old = $this->node(1, ['getCreationTime' => 100]);
        $new = $this->node(2, ['getCreationTime' => 200]);

        $this->assertSame([$new, $old], $this->sorted([$old, $new], [$this->order('creation_time', desc: true)]));
    }

    public function testMetadataValue(): void
    {
        $b = $this->node(1, metadata: ['dcn_core_area' => 'Civiel recht']);
        $a = $this->node(2, metadata: ['dcn_core_area' => 'Bestuursrecht']);

        $this->assertSame([$a, $b], $this->sorted([$b, $a], [$this->order('dcn_core_area', metadata: true)]));
    }

    public function testStringsCompareByteWiseNotAsNumbers(): void
    {
        // utf8mb4_bin puts "100" before "99"; PHP's <=> would not.
        $n99 = $this->node(1, metadata: ['dcn_core_client_number' => '99']);
        $n100 = $this->node(2, metadata: ['dcn_core_client_number' => '100']);

        $this->assertSame([$n100, $n99], $this->sorted([$n99, $n100], [$this->order('dcn_core_client_number', metadata: true)]));
    }

    public function testNamesCompareCaseSensitively(): void
    {
        // Byte order, as the database cut the page: uppercase before lowercase.
        $lower = $this->node(1, ['getName' => 'a.txt']);
        $upper = $this->node(2, ['getName' => 'B.txt']);

        $this->assertSame([$upper, $lower], $this->sorted([$lower, $upper], [$this->order('name')]));
    }

    public function testMissingMetadataIsSmallestOnMysql(): void
    {
        $none = $this->node(1);
        $some = $this->node(2, metadata: ['dcn_core_area' => 'Civiel recht']);
        $orders = [$this->order('dcn_core_area', metadata: true)];

        $this->assertSame([$none, $some], $this->sorted([$some, $none], $orders, nullIsSmallest: true));
    }

    public function testMissingMetadataIsLargestOnPostgres(): void
    {
        $none = $this->node(1);
        $some = $this->node(2, metadata: ['dcn_core_area' => 'Civiel recht']);
        $orders = [$this->order('dcn_core_area', metadata: true)];

        $this->assertSame([$some, $none], $this->sorted([$none, $some], $orders, nullIsSmallest: false));
    }

    public function testDescendingFlipsWhereMissingValuesGo(): void
    {
        // ORDER BY … DESC reverses NULLs too: last on MySQL.
        $none = $this->node(1);
        $some = $this->node(2, metadata: ['dcn_core_area' => 'Civiel recht']);
        $orders = [$this->order('dcn_core_area', desc: true, metadata: true)];

        $this->assertSame([$some, $none], $this->sorted([$none, $some], $orders, nullIsSmallest: true));
    }

    public function testFileIdSettlesTies(): void
    {
        $second = $this->node(8, ['getMTime' => 5]);
        $first = $this->node(3, ['getMTime' => 5]);
        $orders = [$this->order('mtime', desc: true), $this->order('fileid')];

        $this->assertSame([$first, $second], $this->sorted([$second, $first], $orders));
    }

    public function testEverySortFinderOffersHasAnOrdering(): void
    {
        // A sort added to FileQuery::SORTS without one here would be cut by
        // the database and then merged in some other order.
        foreach (FileQuery::SORTS as $sort) {
            ResultOrder::comparator([$this->order($sort)], true);
        }
        $this->addToAssertionCount(count(FileQuery::SORTS));
    }

    public function testCoreKeepsThePlainSorts(): void
    {
        $this->assertTrue(ResultOrder::coreSortsBy([$this->order('name', desc: true), $this->order('fileid')]));
    }

    public function testFinderResortsWhatCoreCannotCompare(): void
    {
        $this->assertFalse(ResultOrder::coreSortsBy([$this->order('creation_time'), $this->order('fileid')]));
        $this->assertFalse(ResultOrder::coreSortsBy([$this->order('dcn_core_area', metadata: true), $this->order('fileid')]));
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectException(LogicException::class);

        ResultOrder::comparator([$this->order('permissions')], true);
    }

    /**
     * @param list<Node> $nodes
     * @param list<ISearchOrder> $orders
     * @return list<Node>
     */
    private function sorted(array $nodes, array $orders, bool $nullIsSmallest = true): array
    {
        usort($nodes, ResultOrder::comparator($orders, $nullIsSmallest));

        return $nodes;
    }

    private function order(string $field, bool $desc = false, bool $metadata = false): ISearchOrder
    {
        $order = $this->createMock(ISearchOrder::class);
        $order->method('getField')->willReturn($field);
        $order->method('getExtra')->willReturn($metadata ? IMetadataQuery::EXTRA : '');
        $order->method('getDirection')->willReturn($desc ? ISearchOrder::DIRECTION_DESCENDING : ISearchOrder::DIRECTION_ASCENDING);

        return $order;
    }

    /**
     * @param array<string, mixed> $values getter => value
     * @param array<string, mixed> $metadata
     */
    private function node(int $id, array $values = [], array $metadata = []): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);
        $node->method('getMetadata')->willReturn($metadata);
        foreach ($values as $getter => $value) {
            $node->method($getter)->willReturn($value);
        }

        return $node;
    }
}
