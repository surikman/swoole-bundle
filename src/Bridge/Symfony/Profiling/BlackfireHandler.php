<?php
declare(strict_types=1);
/*
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 * @license    Internal use only
 */

namespace K911\Swoole\Bridge\Symfony\Profiling;

use BlackfireProbe;
use K911\Swoole\Server\RequestHandler\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 *
 */
final class BlackfireHandler implements RequestHandlerInterface
{
    /**
     * @var RequestHandlerInterface
     */
    private $decorated;

    /**
     * @var BlackfireResponseProcessor
     */
    private $responseProcessor;

    /**
     * @param RequestHandlerInterface    $decorated
     * @param BlackfireResponseProcessor $responseProcessor
     */
    public function __construct(RequestHandlerInterface $decorated, BlackfireResponseProcessor $responseProcessor)
    {
        $this->decorated = $decorated;
        $this->responseProcessor = $responseProcessor;
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, Response $response): void
    {
        $enableProfiling = isset($request->header['x-blackfire-query']);
        $probe = $enableProfiling ? new BlackfireProbe($request->header['x-blackfire-query']) : null;

        if ($probe) {
            $probe->enable();
        }

        $this->responseProcessor->registerProbe($probe);
        $this->decorated->handle($request, $response);
    }
}
