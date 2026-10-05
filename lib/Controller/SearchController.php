<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Search\FileCondition;
use OCA\DcnFinder\Search\FileQuery;
use OCA\DcnFinder\Search\InvalidQueryException;
use OCA\DcnFinder\Search\MetadataFields;
use OCA\DcnFinder\Service\CoreScope;
use OCA\DcnFinder\Service\FileSearchService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * File search over the user's own files.
 *
 * POST rather than GET because a search is a structured document (a condition
 * list), not a handful of scalars — the same reason WebDAV uses a SEARCH body.
 *
 * Routing: #[FrontpageRoute] → /apps/doconext_finder/… (NOT #[ApiRoute], which
 * is OCS). Declaration order is route registration order; keep literal paths
 * above parametric ones.
 *
 * @psalm-suppress UnusedClass
 */
class SearchController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private FileSearchService $service,
        private MetadataFields $metadataFields,
        private CoreScope $coreScope,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Describes what this server can be asked to filter on, so the UI builds its
     * field and operator menus from the backend rather than a hardcoded copy.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/search/fields')]
    public function fields(): DataResponse
    {
        return new DataResponse([
            'fields'    => FileCondition::FIELDS,
            'operators' => FileCondition::FIELD_OPERATORS,
            'sorts'     => FileQuery::SORTS,
            'maxLimit'  => FileQuery::MAX_LIMIT,
            // Whatever this server's apps registered — when DoCoNEXT Core is
            // installed its fields appear here without Finder knowing about it.
            'metadata'          => $this->metadataFields->all(),
            'metadataOperators' => FileCondition::METADATA_OPERATORS,
        ]);
    }

    /**
     * Typeahead for a metadata field that names people.
     *
     * Core stores those as bare ids — `alice`, and comma-separated where a field
     * names several — so the box that fills one cannot offer what it has never
     * been told. Core knows the names, and it knows the field's own rules about
     * who may be picked: one kind of principal or any, guests in or out, certain
     * groups only. So the field travels there and the answer comes back whole.
     *
     * A field that names nobody, or a server without Core, answers with an empty
     * list rather than an error: the interface offers a plain box there.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/principals')]
    public function principals(): DataResponse
    {
        if ($this->userSession->getUser() === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $field = (string)$this->request->getParam('field', '');
        $key = MetadataFields::isMetadata($field) ? MetadataFields::key($field) : $field;

        if ($this->metadataFields->principalField($key) === null) {
            return new DataResponse(['principals' => []]);
        }

        return new DataResponse([
            'principals' => $this->coreScope->principalSuggestions(
                $key,
                (string)$this->request->getParam('q', ''),
            ),
        ]);
    }

    /**
     * DoCoNEXT Core's vocabulary for narrowing a search: the workspaces this user
     * may see and the entity types within them.
     *
     * Both lists come back at once, types carrying their realmId, so the picker
     * cascades without a round-trip per step — they are short lists, and a menu
     * that stalls on every choice is worse than one that loads a little more.
     * Empty lists mean Core is absent; the folder scope does not come from here
     * and keeps working regardless.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/scope')]
    public function scope(): DataResponse
    {
        if ($this->userSession->getUser() === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse([
            'realms'      => $this->coreScope->realms(),
            'entityTypes' => $this->coreScope->entityTypes(),
        ]);
    }

    /**
     * Typeahead over entities. There are far too many for a dropdown, which is
     * the whole reason this level is a search box and not a third select.
     *
     * An empty `q` answers with suggestions — starred, then recently changed —
     * so the field has something to offer before anything is typed.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/scope/entities')]
    public function scopeEntities(): DataResponse
    {
        if ($this->userSession->getUser() === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $typeId = (int)$this->request->getParam('entityTypeId', 0);

        return new DataResponse([
            'entities' => $this->coreScope->entities(
                (string)$this->request->getParam('q', ''),
                $typeId > 0 ? $typeId : null,
            ),
        ]);
    }

    /**
     * The DoCoNEXT Core entity a file belongs to, if any.
     *
     * Asked on demand rather than carried on every search result: most rows are
     * never acted on, and this is one lookup per file.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/scope/entity-for-file')]
    public function entityForFile(): DataResponse
    {
        if ($this->userSession->getUser() === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fileId = (int)$this->request->getParam('fileId', 0);

        return new DataResponse([
            'entity' => $fileId > 0 ? $this->coreScope->entityForFile($fileId) : null,
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/search')]
    public function search(): DataResponse
    {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new DataResponse(['error' => 'not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $query = FileQuery::fromArray($this->request->getParams());
        } catch (\InvalidArgumentException $e) {
            // Validation messages are written for the caller and carry no internals.
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new DataResponse($this->service->search($uid, $query));
        } catch (InvalidQueryException $e) {
            // Refused by this app, in words written for the caller: ordinary
            // input, so answered without a log line — anyone could otherwise
            // fill the log with stack traces by naming fields that do not exist.
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\InvalidArgumentException $e) {
            // The query builder rejects some operator/field pairings we cannot detect
            // up front. Report its own words rather than a blank failure.
            $this->logger->warning(AppConstants::LOG_PREFIX . ' File search rejected', ['exception' => $e, 'app' => $this->appName]);

            return new DataResponse(
                ['error' => $this->explain($e->getMessage())],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error(AppConstants::LOG_PREFIX . ' File search failed', ['exception' => $e, 'app' => $this->appName]);

            return new DataResponse(['error' => 'search failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Turns a query-builder rejection into something the user can act on.
     *
     * The Group folders app filters search results by ACL with
     * `NOT(path IN <forbidden paths>)`. Core's SplitLargeIn optimizer rewrites an
     * IN of more than 1000 values into `OR(IN, IN, …)`, and SearchBuilder refuses a
     * binary operator inside a NOT — so for an account with that many ACL-denied
     * paths, *every* file search fails, including the Files app's own. Nothing the
     * client sends changes this, so say so rather than implying a bad query.
     */
    private function explain(string $message): string
    {
        if (str_contains($message, 'Binary operators inside "not"')) {
            return 'This account cannot be searched on this server: the Group folders app '
                . 'builds an access filter that the search backend rejects when an account '
                . 'has more than 1000 access-denied paths. File search fails the same way in '
                . 'the Files app for this account. Try an account with fewer group folders.';
        }

        return $message;
    }
}
