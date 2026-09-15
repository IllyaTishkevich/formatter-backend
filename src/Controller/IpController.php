<?php

namespace App\Controller;

use App\Security\ApiAccessGuard;
use App\Service\RequestInspector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class IpController extends AbstractController
{
    public function __construct(
        private readonly ApiAccessGuard $accessGuard,
        private readonly RequestInspector $requestInspector,
    ) {
    }

    #[Route('/api/ip', name: 'api_ip', methods: ['GET', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->accessGuard->isRequestAllowed($request)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $this->accessGuard->withCors($request, new Response('', Response::HTTP_NO_CONTENT), 'GET, OPTIONS');
        }

        return $this->accessGuard->withCors($request, $this->json($this->requestInspector->inspect($request)), 'GET, OPTIONS');
    }
}
