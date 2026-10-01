<?php
declare(strict_types=1);

namespace App\Test\TestCase\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequestFactory;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SecurityHeadersMiddlewareTest extends TestCase
{
    private function handle(string $path, ?Response $response = null): ResponseInterface
    {
        $handler = new class ($response ?? new Response()) implements RequestHandlerInterface {
            public function __construct(private readonly Response $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $request = ServerRequestFactory::fromGlobals(['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET']);

        return (new SecurityHeadersMiddleware())->process($request, $handler);
    }

    public function testAddsHardeningHeaders(): void
    {
        $response = $this->handle('/');

        $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testPrivatePagesAreNotCached(): void
    {
        $response = $this->handle('/c/kasco');

        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    public function testStaticAssetsKeepTheirCacheHeaders(): void
    {
        $response = $this->handle('/css/app.css', (new Response())->withHeader('Cache-Control', 'public, max-age=3600'));

        $this->assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }
}
