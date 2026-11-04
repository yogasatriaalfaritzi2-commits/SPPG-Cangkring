<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundHttpException;
use App\Middleware\Middleware;
use RuntimeException;

final class Router
{
    /**
     * @var array<int, array{
     *     method: string,
     *     regex: string,
     *     action: callable|array{0: class-string, 1: string},
     *     middleware: array<int, string>
     * }>
     */
    private array $routes = [];

    /** @param callable|array{0: class-string, 1: string} $action */
    public function get(string $uri, callable|array $action, array $middleware = []): void
    {
        $this->add('GET', $uri, $action, $middleware);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function post(string $uri, callable|array $action, array $middleware = []): void
    {
        $this->add('POST', $uri, $action, $middleware);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    private function add(string $method, string $uri, callable|array $action, array $middleware): void
    {
        $uri = '/' . trim($uri, '/');
        if ($uri === '/') {
            $regex = '#^/$#';
        } else {
            $pattern = preg_replace_callback(
                '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
                static fn (array $match): string => '(?P<' . $match[1] . '>[^/]+)',
                $uri
            );
            $regex = '#^' . str_replace('#', '\#', (string) $pattern) . '$#';
        }

        $this->routes[] = [
            'method' => $method,
            'regex' => $regex,
            'action' => $action,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $path = $request->path();
        $method = $request->method() === 'HEAD' ? 'GET' : $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowed[$route['method']] = true;
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[] = $value;
                }
            }

            $this->runRoute($request, $route, $params);
            return;
        }

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_keys($allowed)));
            throw new HttpException(405, 'Metode permintaan tidak diizinkan untuk halaman ini.');
        }

        throw new NotFoundHttpException();
    }

    /** @param array{action: callable|array{0: class-string, 1: string}, middleware: array<int, string>} $route */
    private function runRoute(Request $request, array $route, array $params): void
    {
        $next = function (Request $current) use ($route, $params): void {
            $this->callAction($route['action'], $current, $params);
        };

        foreach (array_reverse($route['middleware']) as $definition) {
            $middleware = $this->makeMiddleware((string) $definition);
            $next = static fn (Request $current): mixed => $middleware->handle($current, $next);
        }

        $next($request);
    }

    private function makeMiddleware(string $definition): Middleware
    {
        [$class, $argument] = array_pad(explode(':', $definition, 2), 2, '');
        $class = trim($class);

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, Middleware::class)) {
            throw new RuntimeException('Middleware tidak valid: ' . $definition);
        }

        return $argument === '' ? new $class() : new $class(trim($argument));
    }

    /**
     * @param callable|array{0: class-string, 1: string} $action
     * @param array<int, string> $params
     */
    private function callAction(callable|array $action, Request $request, array $params): void
    {
        if (is_array($action)) {
            [$class, $method] = $action;
            $controller = new $class();
            $controller->{$method}($request, ...$params);
            return;
        }

        $action($request, ...$params);
    }
}
