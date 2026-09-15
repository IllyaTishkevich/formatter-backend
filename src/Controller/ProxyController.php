<?php

namespace App\Controller;

use App\Security\ApiAccessGuard;
use App\Service\ProxyRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ProxyController extends AbstractController
{
    public function __construct(
        private readonly ApiAccessGuard $accessGuard,
        private readonly ProxyRequestService $proxyRequestService,
    ) {
    }

    #[Route('/api/request', name: 'api_request', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->accessGuard->isRequestAllowed($request)) {
            // no CORS headers on purpose - the browser must not be able to read this either
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCors($request, new Response('', Response::HTTP_NO_CONTENT));
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->withCors($request, $this->error('Invalid JSON payload', 400));
        }

        $result = $this->proxyRequestService->forward($payload);

        if (!$result->success) {
            return $this->withCors($request, $this->error($result->error, $result->status));
        }

        return $this->withCors($request, $this->json($result->data));
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['error' => $message], $status);
    }

    private function withCors(Request $request, Response $response): Response
    {
        return $this->accessGuard->withCors($request, $response, 'POST, OPTIONS');
    }
}
