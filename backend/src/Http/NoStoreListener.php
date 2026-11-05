<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** API responses hold private data: no browser or shared cache may store them (Cache-Control: no-store). */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class NoStoreListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->addCacheControlDirective('no-store');
        }
    }
}
