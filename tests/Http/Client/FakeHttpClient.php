<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http\Client;

use Polidog\Relayer\Http\Client\HttpClient;
use Polidog\Relayer\Http\Client\HttpResponse;
use Throwable;

/**
 * Scriptable {@see HttpClient} test double.
 *
 * Counts how many times {@see request()} is invoked (so memoization can be
 * asserted), records the last call's arguments, returns a canned
 * {@see HttpResponse}, and can be told to throw so error paths in the
 * decorators are exercised.
 *
 * Not named `*Test`, so PHPUnit skips it; PSR-4 autoload still loads it.
 */
final class FakeHttpClient implements HttpClient
{
    public int $requestCalls = 0;

    public ?string $lastMethod = null;

    public ?string $lastUrl = null;

    /** @var array<string, string> */
    public array $lastHeaders = [];

    public ?string $lastBody = null;

    public ?Throwable $throw = null;

    public HttpResponse $response;

    public function __construct()
    {
        $this->response = new HttpResponse(200, [], '');
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        ++$this->requestCalls;
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;

        if (null !== $this->throw) {
            throw $this->throw;
        }

        return $this->response;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }
}
