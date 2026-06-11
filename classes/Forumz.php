<?php

namespace Grav\Plugin\Forumz;

use Grav\Common\Grav;

/**
 * Public JSON API for GravForumz.
 */
class Forumz
{
    private Grav $grav;
    private ForumzStorage $storage;
    private bool $bridgeMode = false;
    private int $bridgeHttpCode = 200;
    /** @var array<string, mixed>|null */
    private ?array $jsonBodyOverride = null;
    private const COOKIE = 'forumz_session';

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        require_once __DIR__ . '/ForumzStorage.php';
        $this->storage = new ForumzStorage($grav);
    }

    public function setBridgeMode(bool $enabled): void
    {
        $this->bridgeMode = $enabled;
    }

    public function getBridgeHttpCode(): int
    {
        return $this->bridgeHttpCode;
    }

    /** @param array<string, mixed> $body */
    public function setJsonBodyOverride(array $body): void
    {
        $this->jsonBodyOverride = $body;
    }

    public function handle(string $action): void
    {
        $this->bridgeHttpCode = 200;

        if (!$this->bridgeMode) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            if (!$this->bridgeMode) {
                http_response_code(204);
            }
            return;
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        try {
            switch ($action) {
                case 'boards':
                    $this->requireMethod($method, 'GET');
                    $this->respond($this->storage->listBoards());
                    return;

                case 'threads':
                    $this->requireMethod($method, 'GET');
                    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
                    $showPinned = ($_GET['show_pinned'] ?? 'true') !== 'false';
                    $this->respond($this->storage->listThreads($this->boardFromQuery(), $limit, $showPinned));
                    return;

                case 'thread':
                    if ($method === 'GET') {
                        $this->respond($this->storage->getThread(
                            $this->boardFromQuery(),
                            $this->threadFromQuery()
                        ));
                        return;
                    }
                    if ($method === 'POST') {
                        $this->respond($this->storage->createThread(
                            $this->readJsonBody(),
                            $this->effectiveAuthorSlug()
                        ));
                        return;
                    }
                    $this->fail('Method not allowed', 405);
                    return;

                case 'reply':
                    $this->requireMethod($method, 'POST');
                    $this->respond($this->storage->appendReply(
                        $this->readJsonBody(),
                        $this->effectiveAuthorSlug()
                    ));
                    return;

                case 'profile':
                    if ($method === 'GET') {
                        $slug = (string) ($_GET['user'] ?? $_GET['slug'] ?? '');
                        $this->respond($this->storage->getProfile($slug));
                        return;
                    }
                    if ($method === 'PUT' || $method === 'POST') {
                        $slug = $this->effectiveAuthorSlug();
                        if ($slug === null) {
                            throw new \InvalidArgumentException('Login required.');
                        }
                        $this->respond($this->storage->updateProfileForSlug(
                            $slug,
                            $this->readJsonBody()
                        ));
                        return;
                    }
                    $this->fail('Method not allowed', 405);
                    return;

                case 'profiles':
                    $this->requireMethod($method, 'GET');
                    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 24)));
                    $this->respond($this->storage->listProfiles($limit));
                    return;

                case 'session':
                    $this->requireMethod($method, 'GET');
                    $this->respond($this->storage->sessionProfile($this->sessionToken()));
                    return;

                case 'register':
                    $this->requireMethod($method, 'POST');
                    $result = $this->storage->registerProfile($this->readJsonBody());
                    if (!empty($result['token'])) {
                        $this->setSessionCookie((string) $result['token']);
                    }
                    $this->respond($result);
                    return;

                case 'login':
                    $this->requireMethod($method, 'POST');
                    $result = $this->storage->loginProfile($this->readJsonBody());
                    if (!empty($result['token'])) {
                        $this->setSessionCookie((string) $result['token']);
                    }
                    $this->respond($result);
                    return;

                case 'logout':
                    $this->requireMethod($method, 'POST');
                    $token = $this->sessionToken();
                    if ($token !== null) {
                        $this->storage->logoutSession($token);
                    }
                    $this->clearSessionCookie();
                    $this->respond(['ok' => true, 'message' => 'Logged out.']);
                    return;

                default:
                    $this->fail('Unknown Forumz route.', 404);
            }
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->fail('Forum tribble malfunction.', 500);
        }
    }

    private function requireMethod(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new \InvalidArgumentException('Method not allowed');
        }
    }

    private function sessionToken(): ?string
    {
        $header = (string) ($_SERVER['HTTP_X_FORUMZ_SESSION'] ?? $_SERVER['HTTP_X_MUD_FORUMZ_SESSION'] ?? '');
        if ($header !== '' && preg_match('/^[a-f0-9]{32,64}$/', $header)) {
            return $header;
        }
        return isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : null;
    }

    private function sessionSlug(): ?string
    {
        return $this->storage->sessionSlug($this->sessionToken());
    }

    private function effectiveAuthorSlug(): ?string
    {
        return $this->storage->effectiveAuthorSlug($this->sessionToken());
    }

    private function requireSessionToken(): string
    {
        $token = $this->sessionToken();
        if ($token === null || $this->sessionSlug() === null) {
            throw new \InvalidArgumentException('Login required.');
        }
        return $token;
    }

    private function setSessionCookie(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearSessionCookie(): void
    {
        setcookie(self::COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function boardFromQuery(): string
    {
        return (string) ($_GET['board'] ?? '');
    }

    private function threadFromQuery(): string
    {
        return (string) ($_GET['thread'] ?? '');
    }

    /** @return array<string, mixed> */
    private function readJsonBody(): array
    {
        if ($this->jsonBodyOverride !== null) {
            return $this->jsonBodyOverride;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            throw new \InvalidArgumentException('Empty payload.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON.');
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function fail(string $message, int $code): void
    {
        if (!$this->bridgeMode) {
            http_response_code($code);
        } else {
            $this->bridgeHttpCode = $code;
        }
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }
}
