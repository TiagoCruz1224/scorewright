<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 413 for a body larger than PHP's post_max_size (6 MiB: a 5 MB import file plus the multipart overhead, architecture
 * §9). PHP drops such a body before Symfony runs, so without this check the request would just look empty. Caddy
 * (request_body max_size in the Caddyfile) cuts off a body without Content-Length at the same size.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class RequestBodyLimitListener
{
    public function __invoke(RequestEvent $event): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        if ($event->isMainRequest() && $limit > 0 && (int) $event->getRequest()->headers->get('Content-Length') > $limit) {
            throw new ApiProblem(413, 'payload_too_large', "The request body is larger than the limit of $limit bytes");
        }
    }
}
