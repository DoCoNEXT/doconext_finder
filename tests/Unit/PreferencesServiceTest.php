<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Search\MetadataFields;
use OCA\DcnFinder\Service\PreferencesService;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;

/**
 * Host-pure unit test for the preference sanitiser — the layer that decides
 * what a stored preference is allowed to be.
 */
class PreferencesServiceTest extends TestCase
{
    private function serviceReading(array $stored): PreferencesService
    {
        $userConfig = $this->createMock(IUserConfig::class);
        $userConfig->method('getValueArray')->willReturn($stored);

        return new PreferencesService($userConfig, $this->createMock(MetadataFields::class));
    }

    public function testHighlightColourIsKeptWhenItIsHex(): void
    {
        $preferences = $this->serviceReading(['highlightColor' => '#FFD54F'])->get('alice');

        // Lower-cased on the way in, so two spellings of one colour compare equal.
        $this->assertSame('#ffd54f', $preferences['highlightColor']);
    }

    public function testHighlightColourFallsBackToTheDefault(): void
    {
        // The value is written into a CSS custom property; anything that is not
        // plainly a colour is dropped rather than passed through, which is what
        // stops a stored preference from carrying a declaration into the page.
        foreach (['red', '#fff', 'rgb(1,2,3)', '#ffffff; position: fixed', '', null, ['#ffffff']] as $raw) {
            $preferences = $this->serviceReading(['highlightColor' => $raw])->get('alice');

            $this->assertSame('', $preferences['highlightColor'], var_export($raw, true) . ' should be refused');
        }
    }

    public function testHighlightColourIsEmptyWhenNeverSet(): void
    {
        $this->assertSame('', $this->serviceReading([])->get('alice')['highlightColor']);
    }

    public function testDraggedSidebarWidthIsKeptWithinItsBounds(): void
    {
        foreach ([300, 512, 1200] as $width) {
            $this->assertSame($width, $this->serviceReading(['sidebarWidth' => $width])->get('alice')['sidebarWidth']);
        }

        // A string is what a JSON body round-trips a number as often enough.
        $this->assertSame(512, $this->serviceReading(['sidebarWidth' => '512'])->get('alice')['sidebarWidth']);
    }

    public function testUnusableSidebarWidthGivesThePanelItsOwnSizingBack(): void
    {
        // Refused rather than clamped: a width outside the bounds says the value
        // did not come from a drag, so guessing at what it meant is worse than
        // letting the panel size itself to the window again.
        foreach ([299, 1201, -100, 0, null, 'wide', ['512']] as $raw) {
            $preferences = $this->serviceReading(['sidebarWidth' => $raw])->get('alice');

            $this->assertSame(0, $preferences['sidebarWidth'], var_export($raw, true) . ' should be refused');
        }

        $this->assertSame(0, $this->serviceReading([])->get('alice')['sidebarWidth']);
    }
}
