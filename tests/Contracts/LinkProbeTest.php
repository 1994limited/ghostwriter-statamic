<?php

namespace NineteenNinetyFour\Ghostwriter\Tests\Contracts;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\HttpLinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkProbe;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\LinkProbeContract;
use NineteenNinetyFour\Ghostwriter\Tests\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Core's LinkProbeContract against the probe the addon binds (core's
 * HttpLinkProbe over the addon's HTTP clients), with the clients answering
 * as each test says, so no request leaves.
 */
final class LinkProbeTest extends TestCase
{
    use LinkProbeContract;

    protected function linkProbe(array $answers): LinkProbe
    {
        $this->app->instance(HttpClients::class, new class($answers) implements ClientInterface, HttpClients
        {
            /**
             * @param  array<string, int|string|array<string, int>>  $answers
             */
            public function __construct(private readonly array $answers) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $answer = $this->answers[(string) $request->getUri()] ?? 404;

                if ($answer === 'dns') {
                    throw (new NetworkError('cURL error 6: Could not resolve host: gone.example'))->withRequest($request);
                }

                if ($answer === 'timeout') {
                    throw NetworkError::timedOut(10)->withRequest($request);
                }

                return new Response(is_array($answer) ? ($answer[$request->getMethod()] ?? 404) : (int) $answer);
            }

            public function client(int $timeout): ClientInterface
            {
                return $this;
            }

            public function requestFactory(): RequestFactoryInterface
            {
                return new HttpFactory;
            }

            public function streamFactory(): StreamFactoryInterface
            {
                return new HttpFactory;
            }
        });

        $probe = app(LinkProbe::class);
        $this->assertInstanceOf(HttpLinkProbe::class, $probe);

        return $probe;
    }
}
