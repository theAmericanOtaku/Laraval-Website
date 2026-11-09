<?php

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's API in tests. Register a response per "METHOD /path" and
 * every request the SDK makes is answered from here and recorded in $requests.
 */
class FakeStripe implements ClientInterface
{
    /** @var array<int, array{method: string, path: string, params: array}> */
    public array $requests = [];

    /** @param array<string, array|callable> $routes e.g. ['GET /v1/prices' => [...]] */
    public function __construct(private array $routes) {}

    public static function install(array $routes): self
    {
        $fake = new self($routes);
        ApiRequestor::setHttpClient($fake);

        return $fake;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = parse_url($absUrl, PHP_URL_PATH);
        $route = strtoupper($method).' '.$path;
        $this->requests[] = ['method' => strtoupper($method), 'path' => $path, 'params' => $params];

        if (! array_key_exists($route, $this->routes)) {
            return [json_encode(['error' => ['message' => "FakeStripe has no route for $route"]]), 404, []];
        }

        $body = $this->routes[$route];
        $body = is_callable($body) ? $body($params) : $body;

        return [json_encode($body), 200, []];
    }

    /** Requests sent to a route, e.g. sent('POST /v1/prices'). */
    public function sent(string $route): array
    {
        [$method, $path] = explode(' ', $route, 2);

        return array_values(array_filter($this->requests, fn ($r) => $r['method'] === $method && $r['path'] === $path));
    }
}
