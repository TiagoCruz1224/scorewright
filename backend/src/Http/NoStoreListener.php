<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API responses hold private data: no browser or shared cache may store them (Cache-Control: no-store). And a browser
 * that opens one by itself saves it as a file, never renders it (ASVS 14.4.2); fetch() ignores the header, and the
 * CSV export names its own file.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class NoStoreListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $headers = $event->getResponse()->headers;
            $headers->addCacheControlDirective('no-store');
            if (!$headers->has('Content-Disposition')) {
                $headers->set('Content-Disposition', 'attachment; filename="api.json"');
            }
        }
    }
}
