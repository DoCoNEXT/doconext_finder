<?php

declare(strict_types=1);

// PHPUnit bootstrap — the app autoloader plus the nextcloud/ocp stubs (dev dep)
// are enough for host-pure unit tests (no running Nextcloud required). Tests
// that need a live NC instance would load it separately behind an env flag.
//
// The stubs ship without an autoload section of their own — they exist for
// static analysis — so a PSR-4 loader for them is registered here. Without it
// anything touching an OCP interface or constant dies with "class not found",
// which rules out testing the layer that guards what reaches Nextcloud.
require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'OCP\\')) {
        return;
    }

    $file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// One private interface the stubs themselves depend on: OCP\Files\IRootFolder
// extends OC\Hooks\Emitter, so mocking the root folder — which is how a test
// checks that a file id is resolved through the caller's own home — needs it.
// A stub of our own rather than core's file, so the rule below still holds.
spl_autoload_register(static function (string $class): void {
    if ($class === 'OC\\Hooks\\Emitter') {
        require_once __DIR__ . '/stubs/OC/Hooks/Emitter.php';
    }
});

// The platform suite (tests/Platform) checks this app against the core-private
// search classes it constructs, which no stub package ships. They come from a
// Nextcloud server checkout: $NEXTCLOUD_SERVER_DIR in CI, or the server this
// app sits in during development (apps-extra/doconext_finder). Only
// OC\Files\Search\ is mapped, on purpose: a test that reaches further into
// core should fail to load rather than quietly depend on it.
$serverDir = getenv('NEXTCLOUD_SERVER_DIR') ?: __DIR__ . '/../../..';
spl_autoload_register(static function (string $class) use ($serverDir): void {
    if (!str_starts_with($class, 'OC\\Files\\Search\\')) {
        return;
    }

    $file = $serverDir . '/lib/private/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
