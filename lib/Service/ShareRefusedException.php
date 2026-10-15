<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

/**
 * Nextcloud's sharing rules do not allow this share.
 *
 * Carries a reason code next to the English message because Finder translates
 * in the browser only: the page turns the code into a sentence in the reader's
 * language, and the message is for logs and for any client that does not.
 */
class ShareRefusedException extends \DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
