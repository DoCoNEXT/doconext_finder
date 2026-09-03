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
