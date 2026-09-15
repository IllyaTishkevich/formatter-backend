<?php

namespace App\Controller;

use App\Service\TrackingPixelService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Serves a 1x1 tracking pixel meant to be embedded as an <img> on third-party pages.
 * Every request is logged into block_check (ip + timestamp) so stats such as "how many
 * times this placement re-rendered" and "views per day" can be derived later from that table.
 */
class BlockCheckImageController extends AbstractController
{
    public function __construct(private readonly TrackingPixelService $trackingPixelService)
    {
    }

    #[Route('/api/check/image', name: 'api_check_image', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $this->trackingPixelService->recordHit($request->getClientIp());

        return $this->trackingPixelService->buildPixelResponse();
    }
}
