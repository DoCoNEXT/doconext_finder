<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Controller;

use OCA\DcnFinder\Service\CoreDistiller;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Plain-language search: the browser asks this app, and this app asks Core.
 *
 * The browser does not call Core itself. One app, one API, and the coupling to
 * Core stays a server-side detail — which also means the question is refused or
 * answered by the same session that asked it, with no second surface to keep
 * in step.
 *
 * Its own controller rather than three more routes on SearchController, which
 * already proxies Core for the same page, because the two answer failure the
 * other way round: a scope or principal lookup that fails falls back to a
 * usable screen, while a question that was refused has to arrive as a refusal
 * — 401, 403, 404, 400 — or the person asking is told nothing happened. One
 * class, one contract.
 *
 * Routing: #[FrontpageRoute] → /apps/doconext_finder/… (NOT #[ApiRoute], which
 * is OCS). Declaration order is route registration order; the literal paths
 * below stay above the parametric one.
 *
 * @psalm-suppress UnusedClass
 */
class AiController extends ApiController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private CoreDistiller $distiller,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Whether to offer plain-language search at all. An instance without Core,
     * or with its AI switched off, answers `available: false` rather than an
     * error — there is nothing wrong, the feature simply is not there.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/ai/status')]
    public function status(): DataResponse
    {
        return new DataResponse($this->distiller->status());
    }

    /**
     * Hand a question over and get a task id back. The work is a model round
     * trip, so it is scheduled rather than awaited.
     *
     * Body: { question }. Returns { taskId }.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/api/ai/distil')]
    public function distil(): DataResponse
    {
        $question = (string) ($this->request->getParam('question') ?? '');

        try {
            return new DataResponse(
                ['taskId' => $this->distiller->start($question)],
                Http::STATUS_ACCEPTED,
            );
        } catch (\RuntimeException $e) {
            return $this->refused($e);
        }
    }

    /**
     * One poll of a running distillation: `running`, `failed`, `empty_scope`,
     * or `ready` with the filters that were understood.
     */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/api/ai/distil/{taskId}')]
    public function distilResult(int $taskId): DataResponse
    {
        try {
            return new DataResponse($this->distiller->poll($taskId));
        } catch (\RuntimeException $e) {
            return $this->refused($e);
        }
    }

    /**
     * The status travels from Core's refusal, so the browser can tell "not for
     * you" from "not working". An unexpected code is nobody's answer but ours.
     */
    private function refused(\RuntimeException $e): DataResponse
    {
        $code = (int) $e->getCode();
        $known = in_array($code, [
            Http::STATUS_BAD_REQUEST,
            Http::STATUS_UNAUTHORIZED,
            Http::STATUS_FORBIDDEN,
            Http::STATUS_NOT_FOUND,
        ], true);

        return new DataResponse(
            ['error' => $e->getMessage()],
            $known ? $code : Http::STATUS_INTERNAL_SERVER_ERROR,
        );
    }
}
