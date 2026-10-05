<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Tests\Unit;

use OCA\DcnFinder\Service\CoreDistiller;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The length ceiling on a plain-language question, which is enforced here so a
 * question too long never reaches Core — and so never becomes a model task.
 */
class CoreDistillerTest extends TestCase
{
    public function testAQuestionOverTheCeilingIsRefusedBeforeCoreIsAsked(): void
    {
        $apps = $this->createMock(IAppManager::class);
        $container = $this->createMock(ContainerInterface::class);
        $apps->expects($this->never())->method('isEnabledForUser');
        $container->expects($this->never())->method('get');

        $distiller = new CoreDistiller($apps, $container, $this->createMock(LoggerInterface::class));

        try {
            $distiller->start(str_repeat('a', CoreDistiller::MAX_QUESTION_LENGTH + 1));
            $this->fail('an over-long question was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame(Http::STATUS_BAD_REQUEST, $e->getCode());
        }
    }

    public function testAQuestionAtTheCeilingGoesOnToCore(): void
    {
        // Without Core the answer is "no distiller here" — which proves the
        // length check let it through to the point of asking.
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(false);

        $distiller = new CoreDistiller($apps, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

        try {
            $distiller->start(str_repeat('é', CoreDistiller::MAX_QUESTION_LENGTH));
            $this->fail('expected the missing-Core refusal');
        } catch (\RuntimeException $e) {
            $this->assertSame(Http::STATUS_NOT_FOUND, $e->getCode());
        }
    }
}
