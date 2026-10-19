<?php

declare(strict_types=1);

namespace GermanCoach\Core;

/**
 * Small explicit router. Patterns use {name} for a numeric id segment by default,
 * or {name:regex} for a custom constraint, e.g. /api/sessions/{id}/answer.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param array{auth?:bool,csrf?:bool,name?:string} $options
     */
    public function add(string $method, string $pattern, callable|array $handler, array $options = []): Route
    {
        $route = new Route(strtoupper($method), $pattern, self::compile($pattern), $handler, $options);
        $this->routes[] = $route;
        return $route;
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function get(string $pattern, callable|array $handler, array $options = []): Route
    {
        return $this->add('GET', $pattern, $handler, $options);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function post(string $pattern, callable|array $handler, array $options = []): Route
    {
        return $this->add('POST', $pattern, $handler, $options);
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    public function delete(string $pattern, callable|array $handler, array $options = []): Route
    {
        return $this->add('DELETE', $pattern, $handler, $options);
    }

    /**
     * @return array{0:Route,1:array<string,string>}
     * @throws HttpException 404 when no path matches, 405 when the method is wrong
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route->regex, $path, $m)) {
                continue;
            }
            $routeMethod = $route->method;
            if ($routeMethod === $method || ($method === 'HEAD' && $routeMethod === 'GET')) {
                $params = array_filter($m, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
                return [$route, array_map('strval', $params)];
            }
            $allowed[] = $routeMethod;
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw HttpException::notFound();
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    private static function compile(string $pattern): string
    {
        $pattern = '/' . trim($pattern, '/');
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static fn (array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[0-9]{1,18}') . ')',
            self::quoteStatic($pattern)
        );
        return '#^' . $regex . '$#';
    }

    /** Quote regex metacharacters outside {placeholders}. */
    private static function quoteStatic(string $pattern): string
    {
        $parts = preg_split('/(\{[^}]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $out .= str_starts_with($part, '{') ? $part : preg_quote($part, '#');
        }
        return $out;
    }
}
