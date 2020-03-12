<?php
declare(strict_types=1);
/*
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 * @license    Internal use only
 */

namespace K911\Swoole\Bridge\Symfony\Profiling;

use K911\Swoole\Server\RequestHandler\RequestHandlerInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 */
final class BlackfireMultiRequestHandler implements RequestHandlerInterface
{
    /**
     * @var RequestHandlerInterface
     */
    private $decorated;

    /**
     * @var MultiRequestProfiler
     */
    private $profiler;

    /**
     * @param RequestHandlerInterface $decorated
     * @param MultiRequestProfiler    $profiler
     */
    public function __construct(RequestHandlerInterface $decorated, MultiRequestProfiler $profiler)
    {
        $this->decorated = $decorated;
        $this->profiler = $profiler;
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, Response $response): void
    {
        if (isset($request->get['blackfire_start'])) {
            $this->profiler->startProfiling($request->get['blackfire_start']);
        }

        $this->profiler->markNewRequest($request);
        $this->decorated->handle($request, $response);

        if (isset($request->get['blackfire_stop'])) {
            $this->profiler->stopProfiling();
        }
    }
}
