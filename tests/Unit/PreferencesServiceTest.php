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

}
