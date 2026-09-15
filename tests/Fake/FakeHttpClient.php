<?php

declare(strict_types=1);

namespace LaSouris\CreditCheck\Laravel\Tests\Fake;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that answers with queued responses and records what it was asked to send.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function queue(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new LogicException('FakeHttpClient received an unexpected request: ' . $request->getUri());
        }

        return array_shift($this->queue);
    }

    /**
     * @return list<string> The URIs of every request sent so far.
     */
    public function uris(): array
    {
        return array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests);
    }
}
