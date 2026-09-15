<?php

namespace App\Service;

use App\Entity\BlockCheck;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backs the tracking pixel embedded as an <img> on third-party pages: records every
 * hit into block_check (ip + timestamp) and serves back the 1x1 gif.
 */
class TrackingPixelService
{
    // Smallest valid GIF: a single-pixel, 2-color image with no metadata (34 bytes).
    private const PIXEL_GIF_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function recordHit(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $blockCheck = (new BlockCheck())->setIp($ip);
        $this->em->persist($blockCheck);
        $this->em->flush();
    }

    public function buildPixelResponse(): Response
    {
        $pixel = base64_decode(self::PIXEL_GIF_BASE64, true);

        $response = new Response($pixel, Response::HTTP_OK, [
            'Content-Type' => 'image/gif',
            'Content-Length' => (string) strlen($pixel),
        ]);

        // Every load must reach the server - caching would silently break the view counter.
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }
}
