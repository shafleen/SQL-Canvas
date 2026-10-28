<?php

declare(strict_types=1);

namespace SqlCanvas\Http;

final class Router
{
    /** @var list<array{method:string,path:string,handler:callable(Request,array<string,string>):Response}> */
    private array $routes = [];

    /** @param callable(Request,array<string,string>): Response $handler */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /** @param callable(Request,array<string,string>): Response $handler */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->match($route['path'], $request->path);
            if ($params !== null) {
                return ($route['handler'])($request, $params);
            }
        }

        return Response::json([
            'ok' => false,
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
            ],
            'requestId' => self::requestId(),
        ], 404);
    }

    /** @param callable(Request,array<string,string>): Response $handler */
    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $names = [];
        $quoted = preg_quote($pattern, '#');
        $regex = preg_replace_callback('/\\\{([A-Za-z][A-Za-z0-9_]*)\\\}/', static function (array $match) use (&$names): string {
            $names[] = $match[1];
            return '([^/]+)';
        }, $quoted);

        if (!is_string($regex) || preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($names as $index => $name) {
            $params[$name] = rawurldecode($matches[$index + 1]);
        }

        return $params;
    }

    private static function requestId(): string
    {
        return bin2hex(random_bytes(8));
    }
}

