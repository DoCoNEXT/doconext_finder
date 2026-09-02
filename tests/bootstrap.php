<?php

declare(strict_types=1);

// PHPUnit bootstrap — the app autoloader + nextcloud/ocp stubs (dev dep) are
// enough for host-pure unit tests (no running Nextcloud required). Tests that
// need a live NC instance would load it separately behind an env flag.
require_once __DIR__ . '/../vendor/autoload.php';
