<?php

declare(strict_types=1);

namespace CodeVault;

use RuntimeException;

/**
 * Route table + matcher. Patterns use `{param}` placeholders
 * (e.g. `/clients/{id}`); handlers are closures or `[ClassName, 'method']`
 * pairs resolved through the container so controllers get autowired deps.
 */
class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, params: array<int, string>, handler: mixed}> */
    private array $routes = [];

    public function __construct(
        private readonly Container $container
    ) {
    }

    public function get(string $pattern, mixed $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    private function addRoute(string $method, string $pattern, mixed $handler): void
    {
        $pattern = '/' . trim($pattern, '/');
        $paramNames = [];

        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m) use (&$paramNames) {
            $paramNames[] = $m[1];

            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'params' => $paramNames,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $request->path();
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            array_shift($matches);
            $params = array_combine($route['params'], $matches) ?: [];

            return $this->invoke($route['handler'], $request, $params);
        }

        if ($allowedMethods !== []) {
            return Response::html('405 Method Not Allowed', 405);
        }

        return self::notFound($this->container, $request->path());
    }

    /**
     * The branded 404 page, with the store's categories pulled in live so a
     * visitor who mistypes a URL lands somewhere useful instead of on bare
     * text. Static so controllers can return the same page for a record that
     * doesn't exist (an unknown product id) rather than hand-rolling their own.
     *
     * Everything here is best-effort: if the view is missing, or the database
     * is the very reason the request failed, we fall back to plain text. An
     * error page that throws its own error would replace a clean 404 with a
     * 500 and bury the real problem.
     */
    public static function notFound(Container $container, string $requestedPath = ''): Response
    {
        try {
            $categories = [];

            try {
                $categories = $container->make(\CodeVault\Catalog\ProductGroupRepository::class)->all();
            } catch (\Throwable) {
                // Catalog unreachable — render the page without category links.
            }

            $html = $container->make(View::class)->render('errors.404', [
                'categories' => $categories,
                'requestedPath' => $requestedPath,
            ]);

            return Response::html($html, 404);
        } catch (\Throwable) {
            return Response::html('404 Not Found', 404);
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function invoke(mixed $handler, Request $request, array $params): Response
    {
        if ($handler instanceof \Closure) {
            $result = $handler($request, $params, $this->container);

            return $this->toResponse($result);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = $this->container->make($class);

            if (!method_exists($instance, $method)) {
                throw new RuntimeException("Handler method [{$method}] not found on [{$class}].");
            }

            $result = $instance->$method($request, $params);

            return $this->toResponse($result);
        }

        throw new RuntimeException('Unsupported route handler type.');
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }
}
