<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

/**
 * A search this app refused itself, with a message written for the person who
 * asked — a metadata field that does not exist or is not indexed, say.
 *
 * Its own class so the controller can tell it from a rejection by Nextcloud's
 * query builder: this one is ordinary input and answered without logging it,
 * the other is worth a warning, because it means a pairing this app should have
 * caught up front.
 */
final class InvalidQueryException extends \InvalidArgumentException
{
}
