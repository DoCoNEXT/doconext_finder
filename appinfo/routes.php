<?php

declare(strict_types=1);

/**
 * Routes are defined via #[FrontpageRoute] attributes directly on controller
 * methods (NOT #[ApiRoute] — that registers under /ocs/v2.php/…). This file is
 * intentionally empty; Nextcloud just requires it to exist.
 *
 * Gotcha: method DECLARATION ORDER = route registration order. Declare
 * literal-path routes (e.g. /api/notes/recent) BEFORE parametric ones
 * (/api/notes/{id}) or {id} swallows the literal segment. After changing route
 * attributes, run `php occ maintenance:repair` to clear the route cache.
 */
return ['routes' => []];
