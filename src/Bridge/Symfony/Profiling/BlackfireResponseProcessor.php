<?php
declare(strict_types=1);
/*
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 * @license    Internal use only
 */

namespace K911\Swoole\Bridge\Symfony\Profiling;

use BlackfireProbe;
use K911\Swoole\Bridge\Symfony\HttpFoundation\ResponseProcessorInterface;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 */
final class BlackfireResponseProcessor implements ResponseProcessorInterface
{
    /**
     * @var ResponseProcessorInterface
     */
    private $decorated;

    /**
     * @var BlackfireProbe|null
     */
    private $probe;

    /**
     * @param ResponseProcessorInterface $decorated
     */
    public function __construct(ResponseProcessorInterface $decorated)
    {
        $this->decorated = $decorated;
    }

    /**
     * @param BlackfireProbe|null $probe
     */
    public function registerProbe(?BlackfireProbe $probe): void
    {
        $this->probe = $probe;
    }

    /**
     * @param HttpFoundationResponse $httpFoundationResponse
     * @param SwooleResponse         $swooleSwooleResponse
     */
    public function process(HttpFoundationResponse $httpFoundationResponse, SwooleResponse $swooleSwooleResponse): void
    {
        if ($this->probe) {
            $this->probe->close();
            list($probeHeaderName, $probeHeaderValue) = explode(':', $this->probe->getResponseLine(), 2);
            $swooleSwooleResponse->header(strtolower("x-$probeHeaderName"), trim($probeHeaderValue));
            $this->probe = null;
        }

        $this->decorated->process($httpFoundationResponse, $swooleSwooleResponse);
    }
}
