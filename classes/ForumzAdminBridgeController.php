<?php

declare(strict_types=1);

namespace Grav\Plugin\Forumz;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ForumzAdminBridgeController extends AbstractApiController
{
    public const ADMIN_PAGE_SLUG = 'forumz';

    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminRead($request);

        return ApiResponse::create($this->storage()->stats());
    }

    public function boards(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminRead($request);

        return ApiResponse::create($this->storage()->adminBoardsDetail());
    }

    public function saveBoard(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminWrite($request);
        $body = $this->getRequestBody($request);

        return ApiResponse::create($this->storage()->saveAdminBoard(is_array($body) ? $body : []));
    }

    public function deleteBoard(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminWrite($request);
        $body = $this->getRequestBody($request);
        $payload = is_array($body) ? $body : [];

        return ApiResponse::create($this->storage()->deleteAdminBoard(
            (string) ($payload['id'] ?? ''),
            !empty($payload['deleteData']),
            !empty($payload['deleteMforumFile'])
        ));
    }

    public function queue(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminRead($request);

        return ApiResponse::create($this->storage()->moderationQueue());
    }

    public function moderate(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminWrite($request);
        $body = $this->getRequestBody($request);

        return ApiResponse::create($this->storage()->moderate(is_array($body) ? $body : []));
    }

    public function profiles(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return ApiResponse::create(null, 204);
        }
        $this->requireAdminRead($request);

        return ApiResponse::create($this->storage()->adminListProfiles());
    }

    /** @return array<string, mixed> */
    public static function pageDefinition(Config $config): array
    {
        return [
            'id' => self::ADMIN_PAGE_SLUG,
            'plugin' => self::ADMIN_PAGE_SLUG,
            'title' => 'Forumz',
            'icon' => 'fa-comments',
            'page_type' => 'component',
            'has_custom_component' => true,
        ];
    }

    private function storage(): ForumzStorage
    {
        require_once __DIR__ . '/ForumzStorage.php';

        return new ForumzStorage($this->grav);
    }

    private function requireAdminRead(ServerRequestInterface $request): void
    {
        $this->requirePermission($request, 'api.config.read');
    }

    private function requireAdminWrite(ServerRequestInterface $request): void
    {
        $this->requirePermission($request, 'api.config.write');
    }
}
