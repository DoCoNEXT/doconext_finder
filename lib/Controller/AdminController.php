<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Service\BridgeSettings;
use OCA\DcnFinder\Service\FileTypeFilterSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Administrative settings. No #[NoAdminRequired] anywhere here — that attribute is
 * what opens a route to ordinary users, and its absence is what keeps this one shut.
 *
 * @psalm-suppress UnusedClass
 */
class AdminController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private BridgeSettings $bridgeSettings,
        private FileTypeFilterSettings $fileTypeFilterSettings,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Returns what was actually stored rather than what was sent: the value is
     * normalised (lower-cased, dots and duplicates dropped), and the admin should
     * see the list they will actually get.
     */
    #[FrontpageRoute(verb: 'PUT', url: '/api/admin/bridge-extensions')]
    public function setBridgeExtensions(string $extensions = ''): DataResponse
    {
        return new DataResponse(['extensions' => $this->bridgeSettings->setExtensions($extensions)]);
    }

    /**
     * Returns what was actually stored: unknown builtin ids, unusable custom
     * entries and duplicate ids are dropped rather than rejected outright.
     */
    #[FrontpageRoute(verb: 'PUT', url: '/api/admin/file-type-filters')]
    public function setFileTypeFilters(array $filters = []): DataResponse
    {
        return new DataResponse(['filters' => $this->fileTypeFilterSettings->save($filters)]);
    }
}
