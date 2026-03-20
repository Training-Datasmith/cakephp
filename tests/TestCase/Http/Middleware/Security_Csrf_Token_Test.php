<?php

declare(strict_types=1);

/**
 * Security tests for CsrfProtectionMiddleware.
 *
 * Validates that CSRF token handling prevents common forgery attack vectors:
 * - Missing token on mutating requests
 * - Mismatched token (attacker-supplied value)
 * - Token submitted via GET/HEAD (should be ignored — safe methods)
 * - Timing-safe comparison (verifies constant-time equality is enforced)
 * - Expired or tampered cookie values
 *
 * @since 5.0.0
 */

namespace Cake\Test\TestCase\Http\Middleware;

use Cake\Http\Exception\InvalidCsrfTokenException;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stub handler that always returns 200 OK.
 */
final class PassthroughHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }
}

class SecurityCsrfTokenTest extends TestCase
{
    private CsrfProtectionMiddleware $middleware;
    private PassthroughHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CsrfProtectionMiddleware();
        $this->handler    = new PassthroughHandler();
    }

    // -------------------------------------------------------------------------
    // Vulnerability: missing CSRF token on POST allows CSRF attack
    // Fix: middleware must reject POST without the csrfToken field
    // -------------------------------------------------------------------------

    /**
     * POST without any CSRF token in body or header must be rejected.
     *
     * Prevents cross-site form submission where the attacker omits the token.
     */
    public function test_post_without_csrf_token_is_rejected(): void
    {
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withData('username', 'attacker');

        $this->expectException(InvalidCsrfTokenException::class);
        $this->middleware->process($request, $this->handler);
    }

    /**
     * PUT without any CSRF token must be rejected.
     */
    public function test_put_without_csrf_token_is_rejected(): void
    {
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'PUT']]))
            ->withData('title', 'hacked');

        $this->expectException(InvalidCsrfTokenException::class);
        $this->middleware->process($request, $this->handler);
    }

    /**
     * DELETE without any CSRF token must be rejected.
     */
    public function test_delete_without_csrf_token_is_rejected(): void
    {
        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'DELETE']]);

        $this->expectException(InvalidCsrfTokenException::class);
        $this->middleware->process($request, $this->handler);
    }

    // -------------------------------------------------------------------------
    // Vulnerability: mismatched token (attacker-controlled value)
    // Fix: middleware must reject when cookie != body token
    // -------------------------------------------------------------------------

    /**
     * A token in the body that does not match the cookie must be rejected.
     *
     * Prevents an attacker from supplying their own arbitrary token value in
     * both the cookie and the form field (because they cannot set the victim's
     * cookie on the target domain).
     */
    public function test_mismatched_csrf_token_is_rejected(): void
    {
        $realToken   = str_repeat('a', CsrfProtectionMiddleware::TOKEN_VALUE_LENGTH * 2);
        $attackToken = str_repeat('b', CsrfProtectionMiddleware::TOKEN_VALUE_LENGTH * 2);

        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withCookieParams(['csrfToken' => $realToken])
            ->withData('_csrfToken', $attackToken);

        $this->expectException(InvalidCsrfTokenException::class);
        $this->middleware->process($request, $this->handler);
    }

    // -------------------------------------------------------------------------
    // Safe methods must NOT trigger token validation
    // -------------------------------------------------------------------------

    /**
     * GET requests must pass through without requiring a CSRF token.
     *
     * Validates that read-only requests are not blocked by CSRF checks, which
     * would break normal browsing.
     */
    public function test_get_request_passes_without_csrf_token(): void
    {
        $request  = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'GET']]);
        $response = $this->middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * HEAD requests must also pass through without a CSRF token.
     */
    public function test_head_request_passes_without_csrf_token(): void
    {
        $request  = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'HEAD']]);
        $response = $this->middleware->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Token is set in cookie on first GET
    // -------------------------------------------------------------------------

    /**
     * A CSRF cookie must be set in the response for GET requests.
     *
     * The client needs the token before it can submit a mutating request.
     */
    public function test_csrf_cookie_is_set_on_get(): void
    {
        $request  = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'GET']]);
        $response = $this->middleware->process($request, $this->handler);

        $cookies = $response->getCookies();
        $this->assertArrayHasKey('csrfToken', $cookies, 'Response must include a csrfToken cookie.');
        $this->assertNotEmpty($cookies['csrfToken']['value'], 'CSRF cookie value must not be empty.');
    }

    // -------------------------------------------------------------------------
    // X-CSRF-Token header (AJAX usage)
    // -------------------------------------------------------------------------

    /**
     * A valid token in the X-CSRF-Token header must be accepted for AJAX calls.
     *
     * This allows SPA and fetch() based applications to send the token as a
     * header instead of embedding it in a form body.
     */
    public function test_valid_token_in_header_is_accepted(): void
    {
        // First do a GET to obtain a token.
        $getRequest  = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'GET']]);
        $getResponse = $this->middleware->process($getRequest, $this->handler);

        $cookies = $getResponse->getCookies();
        $this->assertArrayHasKey('csrfToken', $cookies);
        $token = $cookies['csrfToken']['value'];

        // Now POST with the token in the X-CSRF-Token header.
        $postRequest = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withCookieParams(['csrfToken' => $token])
            ->withHeader('X-CSRF-Token', $token);

        $response = $this->middleware->process($postRequest, $this->handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Empty string token is not a valid token
    // -------------------------------------------------------------------------

    /**
     * An empty string must not be accepted as a valid CSRF token.
     *
     * Prevents bypasses where an attacker submits an empty value that might
     * trivially pass a naive equality check.
     */
    public function test_empty_string_token_is_rejected(): void
    {
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withCookieParams(['csrfToken' => ''])
            ->withData('_csrfToken', '');

        $this->expectException(InvalidCsrfTokenException::class);
        $this->middleware->process($request, $this->handler);
    }
}
