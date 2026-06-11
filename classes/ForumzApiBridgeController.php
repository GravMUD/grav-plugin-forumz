<?php

declare(strict_types=1);

namespace Grav\Plugin\Forumz;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ForumzApiBridgeController
{
    public function __construct(
        protected readonly Grav $grav,
        protected readonly Config $config,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response(204, ['Access-Control-Allow-Origin' => '*']);
        }

        $_SERVER['REQUEST_METHOD'] = $request->getMethod();

        parse_str($request->getUri()->getQuery(), $query);
        foreach ($query as $key => $value) {
            if (is_string($key)) {
                $_GET[$key] = $value;
            }
        }

        $session = $request->getHeaderLine('X-Forumz-Session');
        if ($session === '') {
            $session = $request->getHeaderLine('X-Mud-Forumz-Session');
        }
        if ($session !== '') {
            $_SERVER['HTTP_X_FORUMZ_SESSION'] = $session;
        }

        $params = $request->getAttribute('route_params', []);
        $action = isset($params['subpath']) ? trim((string) $params['subpath'], '/') : '';

        require_once __DIR__ . '/Forumz.php';
        $forumz = new Forumz($this->grav);
        $forumz->setBridgeMode(true);

        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            $forumz->setJsonBodyOverride($parsed);
        } else {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $forumz->setJsonBodyOverride($decoded);
                }
            }
        }

        $level = ob_get_level();
        ob_start();
        try {
            $forumz->handle($action);
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $code = $forumz->getBridgeHttpCode();
        if ($output === '') {
            return new Response($code >= 400 ? $code : 204, ['Access-Control-Allow-Origin' => '*']);
        }

        return new Response($code, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ], $output);
    }
}
