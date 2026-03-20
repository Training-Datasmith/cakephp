<?php

declare(strict_types=1);

/**
 * CakePHP HTTP — custom middleware pipeline example.
 *
 * Shows how to build a minimal PSR-15 middleware pipeline with CakePHP's
 * Http layer: CSRF protection, CORS headers, and a custom timing middleware.
 *
 * Run:
 *   php -S localhost:8000 examples/02_http_middleware.php
 */

use Cake\Http\MiddlewareQueue;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Middleware\CorsBuilder;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// --- Custom timing middleware -------------------------------------------

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start    = hrtime(true);
        $response = $handler->handle($request);
        $elapsed  = (hrtime(true) - $start) / 1e6; // milliseconds

        return $response->withHeader('X-Response-Time', round($elapsed, 2) . 'ms');
    }
}

// --- Request handler (inner application) --------------------------------

final class HelloHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(
            json_encode(['message' => 'Hello from CakePHP HTTP layer!'], JSON_THROW_ON_ERROR)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }
}

// --- Assemble the pipeline -----------------------------------------------

$queue = new MiddlewareQueue();
$queue->add(new TimingMiddleware());
$queue->add(new CsrfProtectionMiddleware(['httponly' => true]));

$request  = ServerRequestFactory::fromGlobals();
$runner   = new Runner();
$response = $runner->run($queue, $request, new HelloHandler());

// Emit the response.
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}", false);
    }
}
http_response_code($response->getStatusCode());
echo $response->getBody();
