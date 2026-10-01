<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds browser hardening headers to every response and keeps pages that show
 * channel and file names out of browser and proxy caches.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; style-src 'self' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; img-src 'self' data:; object-src 'none'; "
        . "base-uri 'none'; frame-ancestors 'none'; form-action 'self' https://login.microsoftonline.com";

    private const STATIC_PREFIXES = ['/css/', '/js/', '/img/', '/font/'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($this->isStaticAsset($request->getUri()->getPath())) {
            return $response;
        }

        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    private function isStaticAsset(string $path): bool
    {
        foreach (self::STATIC_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
