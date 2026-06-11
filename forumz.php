<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Plugin\Forumz\ForumzApiBridgeController;
use Grav\Plugin\Forumz\ForumzAdminBridgeController;
use Grav\Plugin\Forumz\ForumzFences;
use Grav\Plugin\Forumz\ForumzMambersBridge;
use RocketTheme\Toolbox\Event\Event;

class ForumzPlugin extends Plugin
{
    public const ADMIN_PAGE_SLUG = 'forumz';

    public static function getSubscribedEvents(): array
    {
        $events = [
            'onPluginsInitialized' => [['onPluginsInitializedEarly', 100000]],
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onPageNotFound' => ['onPagesInitialized', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ];

        if ($this->mudAlphaAvailable()) {
            $events['onMudFenceRender'] = ['onMudFenceRender', 0];
        }

        if (self::supportsGravApiBridge()) {
            $events['onApiRegisterRoutes'] = ['onApiRegisterRoutes', 0];
            $events['onApiCollectPublicRoutes'] = ['onApiCollectPublicRoutes', 0];
            $events['onApiSidebarItems'] = ['onApiSidebarItems', 0];
            $events['onApiPluginPageInfo'] = ['onApiPluginPageInfo', 0];
        }

        return $events;
    }

    public function onPluginsInitializedEarly(): void
    {
        if (!$this->isEnabled() || !self::supportsGravApiBridge()) {
            return;
        }

        require_once __DIR__ . '/classes/ForumzApiBridgeController.php';
        require_once __DIR__ . '/classes/ForumzAdminBridgeController.php';
        require_once __DIR__ . '/classes/Forumz.php';
    }

    public function onPagesInitialized(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        $action = $this->apiAction();
        if ($action === null) {
            return;
        }

        if (class_exists(\Grav\Plugin\Api\ApiRouteCollector::class)) {
            return;
        }

        require_once __DIR__ . '/classes/Forumz.php';
        (new \Grav\Plugin\Forumz\Forumz($this->grav))->handle($action);
        exit;
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        require_once __DIR__ . '/classes/ForumzApiBridgeController.php';
        require_once __DIR__ . '/classes/ForumzAdminBridgeController.php';

        $routes = $event['routes'];
        $controller = [ForumzApiBridgeController::class, 'handle'];
        $admin = ForumzAdminBridgeController::class;

        $routes->addRoute(['GET', 'OPTIONS'], '/forumz/admin/stats', [$admin, 'stats']);
        $routes->addRoute(['GET', 'OPTIONS'], '/forumz/admin/boards', [$admin, 'boards']);
        $routes->addRoute(['POST', 'OPTIONS'], '/forumz/admin/boards/save', [$admin, 'saveBoard']);
        $routes->addRoute(['POST', 'OPTIONS'], '/forumz/admin/boards/delete', [$admin, 'deleteBoard']);
        $routes->addRoute(['GET', 'OPTIONS'], '/forumz/admin/queue', [$admin, 'queue']);
        $routes->addRoute(['POST', 'OPTIONS'], '/forumz/admin/moderate', [$admin, 'moderate']);
        $routes->addRoute(['GET', 'OPTIONS'], '/forumz/admin/profiles', [$admin, 'profiles']);

        $routes->addRoute(['GET', 'POST', 'PUT', 'OPTIONS'], '/forumz', $controller);
        $routes->addRoute(['GET', 'POST', 'PUT', 'OPTIONS'], '/forumz/{subpath:.+}', $controller);
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (!$this->isEnabled() || !$this->canUseAdmin2($event['user'] ?? null)) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id' => self::ADMIN_PAGE_SLUG,
            'plugin' => self::ADMIN_PAGE_SLUG,
            'label' => 'Forumz',
            'icon' => 'fa-comments',
            'route' => '/plugin/' . self::ADMIN_PAGE_SLUG,
            'priority' => 82,
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        $plugin = (string) ($event['plugin'] ?? '');
        if (!$this->isEnabled() || $plugin !== self::ADMIN_PAGE_SLUG) {
            return;
        }

        if (!$this->canUseAdmin2($event['user'] ?? null)) {
            return;
        }

        $event['definition'] = ForumzAdminBridgeController::pageDefinition($this->grav['config']);
    }

    /** @param mixed $user */
    private function canUseAdmin2($user): bool
    {
        if (!$user || !is_object($user) || !method_exists($user, 'get')) {
            return false;
        }

        if ($user->get('access.api.super')) {
            return true;
        }

        return (bool) ($user->get('access.api.config.read') || $user->get('access.api.config.write'));
    }

    /** @param Event $event */
    public function onMudFenceRender(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        require_once __DIR__ . '/classes/ForumzFences.php';
        require_once __DIR__ . '/classes/ForumzMambersBridge.php';

        $route = trim((string) $this->grav['config']->get('plugins.forumz.api_route', 'api/forumz'), '/');
        $data = (array) ($event['data'] ?? []);
        $data['api'] = $data['api'] ?? '/' . $route;

        if ($this->mambersAuthEnabled()) {
            $prefix = trim((string) $this->grav['config']->get('plugins.mambers.profile_route_prefix', 'members'), '/');
            $data['mambers_auth'] = '1';
            $data['login_url'] = (string) ($this->grav['config']->get('plugins.mambers.redirect_anonymous_to') ?: '/login');
            $data['register_url'] = '/user_register';
            $data['members_url'] = '/' . ($prefix !== '' ? $prefix : 'members');
        }

        $html = ForumzFences::render(
            strtolower((string) ($event['type'] ?? '')),
            (array) ($event['node'] ?? []),
            (array) ($event['attrs'] ?? []),
            (string) ($event['body'] ?? ''),
            $data
        );

        if ($html !== null && $html !== '') {
            $event['html'] = $html;
            $this->enqueuePublicAssets();
        }
    }

    private function enqueuePublicAssets(): void
    {
        static $done = false;
        if ($done || $this->isAdmin()) {
            return;
        }
        $done = true;

        $assets = $this->grav['assets'];
        $assets->addCss('plugin://forumz/assets/forumz.css');
        $assets->addJs('plugin://forumz/assets/forumz.js', ['group' => 'bottom', 'defer' => true]);
    }

    public function onApiCollectPublicRoutes(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $apiBase = (string) ($event['api_base'] ?? '/api/v1');
        $prefixes = (array) ($event['prefixes'] ?? []);
        $prefixes[] = rtrim($apiBase, '/') . '/forumz';
        $event['prefixes'] = $prefixes;
    }

    public function onTwigInitialized(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigSiteVariables(): void
    {
        $route = trim((string) $this->grav['config']->get('plugins.forumz.api_route', 'api/forumz'), '/');
        $base = rtrim((string) $this->grav['base_url'], '/');

        $this->grav['twig']->twig_vars['forumz'] = [
            'enabled' => $this->isEnabled(),
            'name' => 'Forumz',
            'version' => '1.0.1',
            'api_route' => $route,
            'api' => $base . '/' . $route,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->grav['config']->get('plugins.forumz.enabled', false);
    }

    private function mambersAuthEnabled(): bool
    {
        if ($this->grav['config']->get('plugins.forumz.mambers_identity_bridge') === false) {
            return false;
        }

        $mambersDir = $this->grav['locator']->findResource('plugins://mambers');

        return is_string($mambersDir) && is_dir($mambersDir);
    }

    private function apiAction(): ?string
    {
        $route = trim((string) $this->grav['config']->get('plugins.forumz.api_route', 'api/forumz'), '/');
        $path = trim((string) $this->grav['uri']->path(), '/');

        if ($path === $route) {
            return '';
        }

        if (!str_starts_with($path, $route . '/')) {
            return null;
        }

        return trim(substr($path, strlen($route)), '/');
    }

    private static function supportsGravApiBridge(): bool
    {
        return class_exists(\Grav\Plugin\Api\ApiRouteCollector::class);
    }

    private function mudAlphaAvailable(): bool
    {
        $dir = $this->grav['locator']->findResource('plugins://grav-mud-alpha');

        return is_string($dir) && is_dir($dir);
    }
}
