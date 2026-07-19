<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Container;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $uri): Request
    {
        return new Request([], [], [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
        ], []);
    }

    public function test_dispatches_matching_route_and_extracts_params(): void
    {
        $router = new Router(new Container());
        $router->get('/clients/{id}', function (Request $request, array $params) {
            return Response::html("client:{$params['id']}");
        });

        $response = $router->dispatch($this->request('GET', '/clients/42'));

        $this->assertSame(200, $response->status());
        $this->assertSame('client:42', $this->body($response));
    }

    public function test_returns_404_for_unknown_path(): void
    {
        $router = new Router(new Container());
        $router->get('/', fn () => Response::html('home'));

        $response = $router->dispatch($this->request('GET', '/nope'));

        $this->assertSame(404, $response->status());
    }

    public function test_returns_405_when_path_matches_but_method_does_not(): void
    {
        $router = new Router(new Container());
        $router->post('/clients', fn () => Response::html('created'));

        $response = $router->dispatch($this->request('GET', '/clients'));

        $this->assertSame(405, $response->status());
    }

    public function test_array_return_value_is_wrapped_as_json(): void
    {
        $router = new Router(new Container());
        $router->get('/ping', fn () => ['pong' => true]);

        $response = $router->dispatch($this->request('GET', '/ping'));

        $this->assertSame(200, $response->status());
        $this->assertJsonStringEqualsJsonString('{"pong":true}', $this->body($response));
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }
}
