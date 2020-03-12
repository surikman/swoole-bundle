<?php
declare(strict_types=1);
/*
 * @author     mfris
 * @copyright  PIXELFEDERATION s.r.o.
 * @license    Internal use only
 */

namespace K911\Swoole\Bridge\Symfony\Profiling;

use Blackfire\Client;
use Blackfire\LoopClient;
use Blackfire\Profile\Configuration;
use BlackfireProbe;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request;

/**
 */
final class MultiRequestProfiler
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var LoopClient
     */
    private $blackfireClient;

    /**
     * @var int
     */
    private $counter = 0;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $profileName
     */
    public function startProfiling(string $profileName = ''): void
    {
        $this->stopProfiling();

        $this->blackfireClient = $this->newClient();
        $profileConfig = $this->newConfiguration($profileName);
        $this->blackfireClient->startLoop($profileConfig);
        $this->counter = 0;
    }

    /**
     *
     */
    public function stopProfiling(): void
    {
        if (!$this->isProfiling()) {
            return;
        }

        $this->counter = 0;
        $profile = $this->blackfireClient->endLoop();
        $this->blackfireClient = null;
        $logMessage = $profile ? $profile->getUrl() : 'BlackfireProfiler: Unable to end profiling.';
        $this->logger->debug($logMessage);
    }

    /**
     * @return bool
     */
    public function isProfiling(): bool
    {
        return $this->blackfireClient !== null;
    }

    /**
     * @param Request $request
     */
    public function markNewRequest(Request $request): void
    {
        if (!$this->isProfiling()) {
            return;
        }

        BlackfireProbe::addMarker(
            sprintf(
                "Request nr. #%d, Method: %s, Uri: %s",
                ++$this->counter,
                $request->server['request_method'],
                $request->server['request_uri']
            )
        );
    }

    /**
     * @return LoopClient
     */
    private function newClient(): LoopClient
    {
        $client = new LoopClient(new Client(), 1);

        return $client;
    }

    /**
     * @param string $profileName
     *
     * @return Configuration
     */
    private function newConfiguration(string $profileName = ''): Configuration
    {
        $profileConfig = new Configuration();

        $title = 'SwooleBundle';

        if ($profileName) {
            $title .= ': ' . $profileName;
        }

        $profileConfig->setTitle($title);
        $profileConfig->setMetadata('skip_timeline', 'false');

        return $profileConfig;
    }
}
