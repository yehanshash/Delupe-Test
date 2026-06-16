<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Protects every /api/* endpoint with a simple shared API key supplied in the
 * "X-API-Key" header. The expected key is configured via the API_KEY env var.
 */
final class ApiKeySubscriber implements EventSubscriberInterface
{
    private const HEADER = 'X-API-Key';

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run early, before the controller is resolved.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 64],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api')) {
            return;
        }

        $provided = (string) $event->getRequest()->headers->get(self::HEADER, '');

        if ('' === $this->apiKey) {
            $event->setResponse($this->deny('API key is not configured on the server.'));

            return;
        }

        if ('' === $provided || !hash_equals($this->apiKey, $provided)) {
            $event->setResponse($this->deny('Invalid or missing API key.'));
        }
    }

    private function deny(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Unauthorized', 'message' => $message],
            JsonResponse::HTTP_UNAUTHORIZED,
        );
    }
}
