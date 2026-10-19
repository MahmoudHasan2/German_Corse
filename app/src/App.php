<?php

declare(strict_types=1);

namespace GermanCoach;

use GermanCoach\Core\Clock;
use GermanCoach\Core\Config;
use GermanCoach\Core\Container;
use GermanCoach\Core\Database;
use GermanCoach\Core\Kernel;
use GermanCoach\Core\Logger;
use GermanCoach\Core\Request;
use GermanCoach\Core\Response;
use GermanCoach\Core\Router;
use GermanCoach\Core\Session\NativeSessionStore;

/** Composition root: wires services and routes. Tests override services through container(). */
final class App
{
    private Container $container;
    private Router $router;

    public function __construct(Config $config)
    {
        date_default_timezone_set('UTC');
        $this->container = new Container();
        $this->container->instance(Config::class, $config);
        $this->container->instance(Container::class, $this->container);
        $this->registerServices($config);
        $this->router = new Router();
        Routes::register($this->router);
    }

    public static function fromEnvironment(string $appRoot): self
    {
        return new self(Config::load($appRoot));
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        return (new Kernel($this->container, $this->router))->handle($request);
    }

    private function registerServices(Config $config): void
    {
        $c = $this->container;
        $c->set(Logger::class, static fn () => new Logger(
            $config->isTesting() ? null : $config->path('storage/logs'),
            $config->string('LOG_LEVEL', 'info')
        ));
        $c->set(Database::class, static fn () => Database::fromConfig($config));
        $c->set(Clock::class, static fn () => new Clock());
        $c->set('session.factory', static fn () => static function (Request $request) use ($config) {
            $secureSetting = $config->string('SESSION_SECURE_COOKIE', 'auto');
            $secure = $secureSetting === 'auto' ? $request->isHttps() : $config->bool('SESSION_SECURE_COOKIE');
            return new NativeSessionStore(
                $config->string('SESSION_NAME', 'gac_session'),
                max(5, $config->int('SESSION_LIFETIME_MINUTES', 720)) * 60,
                $secure,
                null
            );
        });
        Services::register($c, $config);
    }
}
