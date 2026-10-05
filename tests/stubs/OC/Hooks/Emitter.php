<?php

declare(strict_types=1);

// Stand-in for core's private OC\Hooks\Emitter, which OCP\Files\IRootFolder
// extends but no stub package ships. Same two methods, so a mock of the root
// folder has the shape the real one has. See tests/bootstrap.php.

namespace OC\Hooks;

interface Emitter
{
    public function listen($scope, $method, callable $callback);

    public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
