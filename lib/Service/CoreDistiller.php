<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turning a plain-language question into filters, by asking DoCoNEXT Core.
 *
 * The question is read against Core's own metadata schema — a field that does
 * not exist and a value outside its option list are dropped there — so the work
 * cannot happen here. Core exposes it as a public, semver-stable service, and
 * this class is the only place in the app that binds it.
 *
 * Same CARVE-OUT as {@see CoreScope}: Core is OPTIONAL, so hard DI on its class
 * would break boot where it is not installed. Bound lazily, by name, and absent
 * means the affordance is not offered rather than an error.
 *
 * Two kinds of failure, kept apart on purpose:
 *
 * - **A refusal** — not signed in, no workspace the caller can reach, an empty
 *   question, no provider. Core answers with an HTTP status in the exception
 *   code, and it travels to the browser unchanged: the person asking needs to
 *   know it was refused, not that nothing happened.
 * - **Anything else** — Core absent, unresolvable, or broken. Logged here and
 *   answered as "no AI here", the same 404 the browser saw when it still called
 *   Core's own route.
 */
class CoreDistiller
{
    private const CORE_APP_ID = 'doconext_core';

    /** Core's public, semver-stable file distiller. Referenced by name so it may be absent. */
    private const CORE_DISTILLER = 'OCA\\DcnCore\\Public\\Ai\\FileSearchDistiller';

    /** A question, not a document: far longer than anyone types into a search box. */
    public const MAX_QUESTION_LENGTH = 1000;

    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether a question can be distilled at all. No Core is not a failure, it
     * is an instance where this feature does not exist.
     *
     * @return array{enabled: bool, available: bool}
     */
    public function status(): array
    {
        $core = $this->core();
        if ($core === null) {
            return ['enabled' => false, 'available' => false];
        }

        try {
            return $core->status();
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Core AI status could not be read', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return ['enabled' => false, 'available' => false];
        }
    }

    /**
     * Schedule one question and return its task id.
     *
     * Refused here rather than left to Core, so a question longer than
     * {@see MAX_QUESTION_LENGTH} never becomes a model task whichever way it
     * arrives.
     *
     * @throws \RuntimeException with an HTTP status as its code
     */
    public function start(string $question): int
    {
        if (mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new \RuntimeException(
                'A question can be at most ' . self::MAX_QUESTION_LENGTH . ' characters',
                Http::STATUS_BAD_REQUEST,
            );
        }

        return (int) $this->ask(static fn (object $core): int => $core->start($question));
    }

    /**
     * One poll of a running distillation.
     *
     * @return array{status: string, understood?: array<string, mixed>}
     * @throws \RuntimeException with an HTTP status as its code
     */
    public function poll(int $taskId): array
    {
        /** @var array{status: string, understood?: array<string, mixed>} */
        return $this->ask(static fn (object $core): array => $core->poll($taskId));
    }

    /**
     * @param callable(object): mixed $call
     * @throws \RuntimeException
     */
    private function ask(callable $call): mixed
    {
        $core = $this->core();
        if ($core === null) {
            throw new \RuntimeException('No distiller on this instance', Http::STATUS_NOT_FOUND);
        }

        try {
            return $call($core);
        } catch (\RuntimeException $e) {
            // A refusal carries the status to answer with; anything else does not.
            if ($this->isRefusal($e)) {
                throw $e;
            }
            throw $this->failed($e);
        } catch (\Throwable $e) {
            throw $this->failed($e);
        }
    }

    private function isRefusal(\RuntimeException $e): bool
    {
        return in_array($e->getCode(), [
            Http::STATUS_BAD_REQUEST,
            Http::STATUS_UNAUTHORIZED,
            Http::STATUS_FORBIDDEN,
            Http::STATUS_NOT_FOUND,
        ], true);
    }

    private function failed(\Throwable $e): \RuntimeException
    {
        $this->logger->warning(AppConstants::LOG_PREFIX . ' Core distillation failed', [
            'exception' => $e,
            'app'       => AppConstants::APP_ID,
        ]);

        return new \RuntimeException('Distillation failed', Http::STATUS_INTERNAL_SERVER_ERROR);
    }

    private function core(): ?object
    {
        if (!$this->appManager->isEnabledForUser(self::CORE_APP_ID)) {
            return null;
        }
        if (!class_exists(self::CORE_DISTILLER)) {
            return null;
        }

        try {
            return $this->container->get(self::CORE_DISTILLER);
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' The Core distiller could not be resolved', [
                'service'   => self::CORE_DISTILLER,
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return null;
        }
    }
}
