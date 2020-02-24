<?php

declare(strict_types=1);

namespace K911\Swoole\Server\RequestHandler\ExceptionHandler;

use ErrorException;
use K911\Swoole\Bridge\Symfony\HttpFoundation\RequestFactoryInterface;
use K911\Swoole\Bridge\Symfony\HttpFoundation\ResponseProcessorInterface;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpFoundation\Request as HttpFoundationRequest;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;

final class ProductionExceptionHandler implements ExceptionHandlerInterface
{
    /**
     * @var HttpKernelInterface
     */
    private $kernel;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    /**
     * @var ResponseProcessorInterface
     */
    private $responseProcessor;

    /**
     * @var ErrorHandler
     */
    private $errorHandler;

    /**
     * @param HttpKernelInterface        $kernel
     * @param RequestFactoryInterface    $requestFactory
     * @param ResponseProcessorInterface $responseProcessor
     */
    public function __construct(
        HttpKernelInterface $kernel,
        RequestFactoryInterface $requestFactory,
        ResponseProcessorInterface $responseProcessor
    ) {
        $this->kernel = $kernel;
        $this->requestFactory = $requestFactory;
        $this->responseProcessor = $responseProcessor;
        $this->errorHandler = new ErrorHandler();
    }

    /**
     * @param Request   $request
     * @param Throwable $exception
     * @param Response  $response
     *
     * @throws Throwable
     * @throws ErrorException
     */
    public function handle(Request $request, Throwable $exception, Response $response): void
    {
        $httpFoundationRequest = $this->requestFactory->make($request);
        $this->errorHandler->setExceptionHandler($this->getExceptionHandler($httpFoundationRequest));
        $httpFoundationResponse = $this->errorHandler->handleException($exception);
        $this->responseProcessor->process($httpFoundationResponse, $response);

        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($httpFoundationRequest, $httpFoundationResponse);
        }
    }

    /**
     * @param HttpFoundationRequest $request
     *
     * @return Callable
     */
    private function getExceptionHandler(HttpFoundationRequest $request): Callable
    {
        $privateHandler = function (HttpKernelInterface $kernel, HttpFoundationRequest $request, Throwable $e) {
            $masterRequest = $kernel->requestStack->getMasterRequest();

            if (!$masterRequest) {
                $masterRequest = $request;
            }

            $type = HttpKernelInterface::MASTER_REQUEST;

            return $kernel->handleThrowable($e, $masterRequest, $type);
        };

        $privateHandler = $privateHandler->bind($privateHandler, null, $this->kernel);
        $kernel = $this->kernel;

        return function(Throwable $e) use ($privateHandler, $kernel, $request) {
            return $privateHandler($kernel, $request, $e);
        };
    }
}
