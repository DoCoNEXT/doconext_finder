<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\IAppConfig;

/**
 * Which files "Open in local app" hands to DoCoNEXT Bridge rather than to the
 * Nextcloud desktop client.
 *
 * The rule lives here, on the server, rather than in the frontend that happens to
 * render the button: DoCoNEXT Core offers the same action, and a rule duplicated
 * across two web apps is a rule that will disagree with itself. Both read it from
 * the same place.
 *
 * Extensions rather than mime types, deliberately: the distinction that matters is
 * what a local application can open, and that follows the file's extension —
 * a .msg is refused by a mail client whatever mime type the server assigned it.
 */
class BridgeSettings
{
    public function __construct(private IAppConfig $appConfig)
    {
    }

    /**
     * Lower-case, dot-less, de-duplicated. An empty list is meaningful: it means
     * everything goes to the desktop client and the bridge is not needed at all.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        $raw = $this->appConfig->getValueString(
            AppConstants::APP_ID,
            AppConstants::BRIDGE_EXTENSIONS_KEY,
            AppConstants::DEFAULT_BRIDGE_EXTENSIONS,
        );

        return self::parse($raw);
    }

    /**
     * @param string $raw comma- or whitespace-separated, with or without leading dots
     * @return list<string> what was actually stored
     */
    public function setExtensions(string $raw): array
    {
        $extensions = self::parse($raw);
        $this->appConfig->setValueString(
            AppConstants::APP_ID,
            AppConstants::BRIDGE_EXTENSIONS_KEY,
            implode(',', $extensions),
        );

        return $extensions;
    }

    /** @return list<string> */
    private static function parse(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtolower(trim($raw))) ?: [];

        $extensions = [];
        foreach ($parts as $part) {
            // Accept ".eml", "eml" and "*.eml" alike — people type what they know.
            $extension = ltrim(trim($part), '*.');
            // Anything but letters and digits is not an extension; drop it rather
            // than store something that can never match.
            if ($extension !== '' && preg_match('/^[a-z0-9]+$/', $extension) === 1) {
                $extensions[$extension] = true;
            }
        }

        return array_keys($extensions);
    }
}
