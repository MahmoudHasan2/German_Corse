<?php

declare(strict_types=1);

namespace GermanCoach\Core;

use GermanCoach\Auth\AuthService;
use GermanCoach\Core\Session\SessionStore;

/**
 * Request pipeline: route → session → CSRF → authentication → handler,
 * with uniform JSON errors and security headers on every response.
 */
final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
    ) {
    }

    public function handle(Request $request): Response
    {
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        /** @var Config $config */
        $config = $this->container->get(Config::class);
        $session = null;
        $this->container->resetRequestScope();

        try {
            [$route, $params] = $this->router->match($request->method, $request->path);
            $request = $request->withAttribute('route', $params);

            if (($route->options['session'] ?? true) === true) {
                /** @var \Closure(Request):SessionStore $factory */
                $factory = $this->container->get('session.factory');
                $session = $factory($request);
                $session->start();
                $this->container->instance(SessionStore::class, $session);
            }

            if ($route->requiresCsrf()) {
                if ($session === null || !(new Csrf($session))->validate($request)) {
                    throw new HttpException(403, 'csrf_invalid', 'Security token missing or expired. Reload the page.');
                }
            }

            if ($route->requiresAuth()) {
                /** @var AuthService $auth */
                $auth = $this->container->get(AuthService::class);
                $user = $auth->currentUser();
                if ($user === null) {
                    throw HttpException::unauthorized();
                }
                $request = $request->withAttribute('user', $user);
            }

            $response = $this->dispatch($route, $request);
        } catch (HttpException $e) {
            $response = Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $logger->requestId());
            foreach ($e->headers as $k => $v) {
                $response->withHeader($k, $v);
            }
            if ($e->status >= 500) {
                $logger->error('http_error', ['code' => $e->errorCode, 'path' => $request->path]);
            }
        } catch (\Throwable $e) {
            $logger->error('unhandled_exception', ['exception' => $e, 'path' => $request->path, 'method' => $request->method]);
            $details = $config->debug() ? ['exception' => $e::class, 'message' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()] : [];
            $response = Response::error(500, 'server_error', 'An internal error occurred.', $details, $logger->requestId());
        }

        if ($session !== null) {
            $cookie = $session->cookieHeader();
            if ($cookie !== null) {
                $response->withHeader('Set-Cookie', $cookie);
            }
        }

        return self::withSecurityHeaders($response, $request, $config);
    }

    private function dispatch(Route $route, Request $request): Response
    {
        $handler = $route->handler;
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            $result = $controller->{$method}($request);
        } else {
            $result = $handler($request, $this->container);
        }
        if (!$result instanceof Response) {
            throw new \LogicException('Handler must return a Response.');
        }
        return $result;
    }

    public static function withSecurityHeaders(Response $response, Request $request, Config $config): Response
    {
        $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'microphone=(self), camera=(), geolocation=(), payment=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; media-src 'self' blob:; "
                . "connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
            );
        if ($config->isProduction() && $request->isHttps()) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
