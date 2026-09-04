<?php

declare(strict_types=1);

namespace OCA\DcnFinder\AppInfo;

use OCA\DcnFinder\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\User\Events\UserDeletedEvent;

/**
 * App bootstrap.
 *
 *  - register(): wire event listeners, middlewares, capabilities, search/
 *    notifier providers, etc. (empty here — add as the app grows).
 *  - boot(): runtime wiring that needs the container, e.g. the navigation
 *    entry (registered dynamically so its label follows the configurable
 *    display name rather than a hardcoded info.xml <navigation>).
 *
 * @psalm-suppress UnusedClass  (referenced by Nextcloud via appinfo)
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = AppConstants::APP_ID;

    public function __construct()
    {
        parent::__construct(self::APP_ID);

        // Load the app's own vendor autoloader when present, so any composer
        // runtime deps are available inside the Nextcloud runtime.
        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }

    public function register(IRegistrationContext $context): void
    {
        // The app's own table is not cleaned up by Nextcloud when an account is
        // deleted, so it cleans up after itself.
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
    }

    public function boot(IBootContext $context): void
    {
        // Top-menu entry, labelled with the admin-configured display name
        // (fallback DEFAULT_DISPLAY_NAME). Registered here rather than in
        // info.xml so the label can be rebranded at runtime.
        $context->injectFn(static function (
            INavigationManager $navigationManager,
            IAppConfig $appConfig,
            IURLGenerator $urlGenerator,
        ): void {
            $navigationManager->add(static function () use ($appConfig, $urlGenerator): array {
                $name = $appConfig->getValueString(self::APP_ID, AppConstants::DISPLAY_NAME_KEY, '');

                return [
                    'id'    => self::APP_ID,
                    'order' => 10,
                    'href'  => $urlGenerator->linkToRoute(self::APP_ID . '.page.index'),
                    'icon'  => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
                    'name'  => $name !== '' ? $name : AppConstants::DEFAULT_DISPLAY_NAME,
                ];
            });
        });
    }
}
