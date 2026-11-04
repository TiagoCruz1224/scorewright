<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF protection for every unsafe /api request (ADR 0002). The SPA sends a random value of at least 24 characters
 * in the csrf-token header: a cross-site page cannot add that header without a CORS preflight, which this API never
 * allows. Symfony's stateless token manager also rejects a request whose Origin, Referer or Sec-Fetch-Site shows
 * another site.
 *
 * Runs after routing (priority 32) and before the firewall (priority 8), so the login request is covered too.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class CsrfListener
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $token = $request->headers->get('csrf-token');
        if (null === $token || !$this->csrfTokenManager->isTokenValid(new CsrfToken('api', $token))) {
            throw new ApiProblem(403, 'csrf_failed', 'Missing or invalid CSRF protection: send the csrf-token header from the same origin');
        }
    }
}
