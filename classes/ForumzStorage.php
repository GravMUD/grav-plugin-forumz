<?php

namespace Grav\Plugin\Forumz;

use Grav\Common\Grav;

/**
 * Flat-file forum + profile storage for GravForumz.
 */
class ForumzStorage
{
    private Grav $grav;
    private string $dir;
    private string $profilesDir;
    private string $sessionsDir;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
        $this->dir = GRAV_ROOT . '/user/data/forumz';
        if (!is_dir($this->dir) && is_dir(GRAV_ROOT . '/user/data/mud-forumz')) {
            $this->dir = GRAV_ROOT . '/user/data/mud-forumz';
        }
        $this->profilesDir = $this->dir . '/profiles';
        $this->sessionsDir = $this->dir . '/_sessions';
    }

    public function dataRoot(): string
    {
        return $this->dir;
    }

    /** @return array<string, array<string, mixed>> */
    public function boardConfig(): array
    {
        require_once __DIR__ . '/ForumzBoardScanner.php';
        $scanned = ForumzBoardScanner::scan($this->grav);

        $boards = $this->grav['config']->get('plugins.forumz.boards');
        if (!is_array($boards)) {
            $boards = [];
        }

        foreach ($scanned as $id => $meta) {
            if (!isset($boards[$id])) {
                $boards[$id] = $meta;
                continue;
            }
            $boards[$id] = array_merge($meta, $boards[$id]);
            $boards[$id]['source'] = 'yaml';
        }

        if ($boards === []) {
            return [
                'general' => [
                    'title' => 'General Discussion',
                    'description' => 'Welcome to the resistance.',
                    'post_policy' => 'open',
                    'source' => 'default',
                ],
            ];
        }

        return $boards;
    }

    /** @return array<string, mixed> */
    public function listBoards(): array
    {
        $boards = [];
        foreach ($this->boardConfig() as $id => $meta) {
            $index = $this->loadIndex($id);
            $threads = array_values(array_filter(
                $index['threads'] ?? [],
                static fn(array $t): bool => !empty($t['approved'])
            ));
            $boards[] = [
                'id' => $id,
                'title' => (string) ($meta['title'] ?? $id),
                'description' => (string) ($meta['description'] ?? ''),
                'postPolicy' => (string) ($meta['post_policy'] ?? 'open'),
                'threadCount' => count($threads),
                'intro' => (string) ($meta['intro'] ?? ''),
                'source' => (string) ($meta['source'] ?? 'yaml'),
            ];
        }

        return ['ok' => true, 'boards' => $boards];
    }

    /** @return array<string, mixed> */
    public function listThreads(string $boardId, int $limit = 20, bool $showPinned = true): array
    {
        $boardId = $this->sanitizeBoardId($boardId);
        $this->assertBoardExists($boardId);

        $index = $this->loadIndex($boardId);
        $threads = array_values(array_filter(
            $index['threads'] ?? [],
            static fn(array $t): bool => !empty($t['approved'])
        ));

        usort($threads, static function (array $a, array $b): int {
            if (!empty($a['pinned']) && empty($b['pinned'])) {
                return -1;
            }
            if (empty($a['pinned']) && !empty($b['pinned'])) {
                return 1;
            }
            return strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? ''));
        });

        if (!$showPinned) {
            $threads = array_values(array_filter($threads, static fn(array $t): bool => empty($t['pinned'])));
        }

        return [
            'ok' => true,
            'boardId' => $boardId,
            'count' => min(count($threads), $limit),
            'threads' => array_slice($threads, 0, $limit),
        ];
    }

    /** @return array<string, mixed> */
    public function getThread(string $boardId, string $threadId, bool $includePending = false): array
    {
        $boardId = $this->sanitizeBoardId($boardId);
        $threadId = $this->sanitizeThreadId($threadId);
        $this->assertBoardExists($boardId);

        $thread = $this->loadThread($boardId, $threadId);
        if ($thread === null) {
            throw new \InvalidArgumentException('Thread not found.');
        }
        if (!$includePending && empty($thread['approved'])) {
            throw new \InvalidArgumentException('Thread not found.');
        }

        $posts = $thread['posts'] ?? [];
        if (!$includePending) {
            $posts = array_values(array_filter($posts, static fn(array $p): bool => !empty($p['approved'])));
        }

        $thread['posts'] = $this->enrichPosts($posts);

        return ['ok' => true, 'thread' => $thread];
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function createThread(array $payload, ?string $authorSlug = null): array
    {
        if (!empty($payload['website'])) {
            return ['ok' => true, 'message' => 'Beam received. Probably spam.'];
        }

        $boardId = $this->sanitizeBoardId((string) ($payload['board'] ?? ''));
        $this->assertBoardExists($boardId);
        $this->assertCanPost($boardId, $authorSlug);

        $author = $this->resolveAuthor($payload, $authorSlug);
        $title = $this->sanitizeTitle((string) ($payload['title'] ?? ''));
        $body = $this->sanitizeBody((string) ($payload['body'] ?? ''));

        $autoApprove = (bool) $this->grav['config']->get('plugins.forumz.auto_approve', true);
        $now = gmdate('c');
        $threadId = $this->uniqueThreadId($title);

        $post = [
            'id' => 'p001',
            'author' => $author['display'],
            'authorSlug' => $author['slug'],
            'body' => $body,
            'created' => $now,
            'approved' => $autoApprove,
        ];

        $thread = [
            'id' => $threadId,
            'boardId' => $boardId,
            'title' => $title,
            'author' => $author['display'],
            'authorSlug' => $author['slug'],
            'created' => $now,
            'updated' => $now,
            'pinned' => false,
            'locked' => false,
            'approved' => $autoApprove,
            'posts' => [$post],
        ];

        $this->saveThread($boardId, $thread);
        $this->upsertIndexEntry($boardId, $thread);
        if ($author['slug'] !== '') {
            $this->bumpProfileStats($author['slug'], true, false);
        }

        return [
            'ok' => true,
            'message' => $autoApprove
                ? 'Thread deployed to the cargo bay. BAAAAHAHAHA.'
                : 'Thread queued for Chief moderation.',
            'threadId' => $threadId,
            'thread' => $autoApprove ? $thread : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function appendReply(array $payload, ?string $authorSlug = null): array
    {
        if (!empty($payload['website'])) {
            return ['ok' => true, 'message' => 'Beam received. Probably spam.'];
        }

        $boardId = $this->sanitizeBoardId((string) ($payload['board'] ?? ''));
        $threadId = $this->sanitizeThreadId((string) ($payload['thread'] ?? ''));
        $this->assertBoardExists($boardId);

        $thread = $this->loadThread($boardId, $threadId);
        if ($thread === null || empty($thread['approved'])) {
            throw new \InvalidArgumentException('Thread not found.');
        }
        if (!empty($thread['locked'])) {
            throw new \InvalidArgumentException('Thread locked. No more tribbles.');
        }

        $this->assertCanPost($boardId, $authorSlug);

        $author = $this->resolveAuthor($payload, $authorSlug);
        $body = $this->sanitizeBody((string) ($payload['body'] ?? ''));

        $autoApprove = (bool) $this->grav['config']->get('plugins.forumz.auto_approve', true);
        $now = gmdate('c');
        $posts = $thread['posts'] ?? [];
        $postNum = count($posts) + 1;

        $post = [
            'id' => 'p' . str_pad((string) $postNum, 3, '0', STR_PAD_LEFT),
            'author' => $author['display'],
            'authorSlug' => $author['slug'],
            'body' => $body,
            'created' => $now,
            'approved' => $autoApprove,
        ];

        $posts[] = $post;
        $thread['posts'] = $posts;
        $thread['updated'] = $now;

        $this->saveThread($boardId, $thread);
        $this->upsertIndexEntry($boardId, $thread);
        if ($author['slug'] !== '') {
            $this->bumpProfileStats($author['slug'], false, true);
        }

        return [
            'ok' => true,
            'message' => $autoApprove
                ? 'Reply beamed. Andy may feel a wee disturbance.'
                : 'Reply queued for moderation.',
            'post' => $autoApprove ? $post : null,
        ];
    }

    /** @return array<string, mixed> */
    public function getProfile(string $slug, bool $includeEmail = false): array
    {
        $slug = $this->sanitizeProfileSlug($slug);
        $profile = $this->loadProfile($slug);
        if ($profile === null) {
            throw new \InvalidArgumentException('Profile not found.');
        }
        if (!empty($profile['banned'])) {
            throw new \InvalidArgumentException('Profile unavailable.');
        }

        return ['ok' => true, 'profile' => $this->publicProfile($profile, $includeEmail)];
    }

    /** @return array<string, mixed> */
    public function listProfiles(int $limit = 24): array
    {
        $profiles = [];
        if (!is_dir($this->profilesDir)) {
            return ['ok' => true, 'profiles' => []];
        }
        foreach (glob($this->profilesDir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !empty($data['banned'])) {
                continue;
            }
            $profiles[] = $this->publicProfile($data, false);
        }

        usort($profiles, static function (array $a, array $b): int {
            $ap = (int) (($a['stats']['posts'] ?? 0) + ($a['stats']['threads'] ?? 0));
            $bp = (int) (($b['stats']['posts'] ?? 0) + ($b['stats']['threads'] ?? 0));
            return $bp <=> $ap;
        });

        return ['ok' => true, 'profiles' => array_slice($profiles, 0, $limit)];
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function registerProfile(array $payload): array
    {
        require_once __DIR__ . '/ForumzMambersBridge.php';
        if (ForumzMambersBridge::identityBridgeEnabled($this->grav)) {
            throw new \InvalidArgumentException('Site login active — use Grav Login / Mambers instead of a separate Forumz passphrase.');
        }

        if (!empty($payload['website'])) {
            return ['ok' => true, 'message' => 'Nice try, spam tribble.'];
        }

        $slug = $this->sanitizeProfileSlug((string) ($payload['slug'] ?? ''));
        if ($this->loadProfile($slug) !== null) {
            throw new \InvalidArgumentException('Callsign taken — pick another slug.');
        }

        $displayName = $this->sanitizeName((string) ($payload['displayName'] ?? $payload['name'] ?? $slug));
        $bio = $this->sanitizeBio((string) ($payload['bio'] ?? ''));
        $avatar = $this->sanitizeAvatar((string) ($payload['avatar'] ?? '🪐'));
        $passphrase = (string) ($payload['passphrase'] ?? $payload['password'] ?? '');

        if (strlen($passphrase) < 6) {
            throw new \InvalidArgumentException('Passphrase required (min 6 chars).');
        }

        $profile = [
            'slug' => $slug,
            'displayName' => $displayName,
            'bio' => $bio,
            'avatar' => $avatar,
            'joined' => gmdate('c'),
            'badges' => ['gravver'],
            'banned' => false,
            'stats' => ['threads' => 0, 'posts' => 0],
            'passwordHash' => password_hash($passphrase, PASSWORD_DEFAULT),
        ];

        $this->saveProfile($profile);
        $token = $this->createSession($slug);

        return [
            'ok' => true,
            'message' => 'Profile forged. Welcome to Forumz.',
            'token' => $token,
            'profile' => $this->publicProfile($profile, false),
        ];
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function loginProfile(array $payload): array
    {
        $slug = $this->sanitizeProfileSlug((string) ($payload['slug'] ?? ''));
        $passphrase = (string) ($payload['passphrase'] ?? $payload['password'] ?? '');
        $profile = $this->loadProfile($slug);

        if ($profile === null || empty($profile['passwordHash'])) {
            throw new \InvalidArgumentException('Unknown callsign or bad passphrase.');
        }
        if (!empty($profile['banned'])) {
            throw new \InvalidArgumentException('Profile banned.');
        }
        if (!password_verify($passphrase, (string) $profile['passwordHash'])) {
            throw new \InvalidArgumentException('Unknown callsign or bad passphrase.');
        }

        $token = $this->createSession($slug);

        return [
            'ok' => true,
            'message' => 'Logged in. Tribbles at ready.',
            'token' => $token,
            'profile' => $this->publicProfile($profile, false),
        ];
    }

    public function logoutSession(string $token): array
    {
        $this->destroySession($token);
        return ['ok' => true, 'message' => 'Logged out.'];
    }

    /** @return array<string, mixed> */
    public function sessionProfile(?string $token): array
    {
        $slug = $this->effectiveAuthorSlug($token);
        if ($slug === null) {
            return ['ok' => true, 'loggedIn' => false, 'profile' => null];
        }

        $profile = $this->loadProfile($slug);
        if ($profile === null || !empty($profile['banned'])) {
            return ['ok' => true, 'loggedIn' => false, 'profile' => null];
        }

        $session = $this->sessionSlug($token);
        $bridged = $session === null && !empty($profile['bridged']);

        return [
            'ok' => true,
            'loggedIn' => true,
            'profile' => $this->publicProfile($profile, false),
            'bridge' => $bridged,
            'source' => $bridged ? 'grav-login' : 'forumz-session',
        ];
    }

    public function effectiveAuthorSlug(?string $token): ?string
    {
        $slug = $this->sessionSlug($token);
        if ($slug !== null) {
            return $slug;
        }

        require_once __DIR__ . '/ForumzMambersBridge.php';
        $user = ForumzMambersBridge::siteUser($this->grav);
        if ($user === null) {
            return null;
        }

        return $this->ensureBridgedProfile($user);
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function updateProfile(string $token, array $payload): array
    {
        $slug = $this->sessionSlug($token);
        if ($slug === null) {
            throw new \InvalidArgumentException('Login required.');
        }

        return $this->updateProfileForSlug($slug, $payload);
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function updateProfileForSlug(string $slug, array $payload): array
    {
        $profile = $this->loadProfile($slug);
        if ($profile === null) {
            throw new \InvalidArgumentException('Profile missing.');
        }

        if (!empty($profile['bridged']) && (isset($payload['passphrase']) || isset($payload['password']))) {
            throw new \InvalidArgumentException('Bridged profile — change password via Grav Login account.');
        }

        if (isset($payload['displayName'])) {
            $profile['displayName'] = $this->sanitizeName((string) $payload['displayName']);
        }
        if (isset($payload['bio'])) {
            $profile['bio'] = $this->sanitizeBio((string) $payload['bio']);
        }
        if (isset($payload['avatar'])) {
            $profile['avatar'] = $this->sanitizeAvatar((string) $payload['avatar']);
        }
        if (!empty($payload['passphrase']) || !empty($payload['password'])) {
            $pass = (string) ($payload['passphrase'] ?? $payload['password']);
            if (strlen($pass) < 6) {
                throw new \InvalidArgumentException('Passphrase min 6 chars.');
            }
            $profile['passwordHash'] = password_hash($pass, PASSWORD_DEFAULT);
        }

        $this->saveProfile($profile);

        return [
            'ok' => true,
            'message' => 'Profile updated.',
            'profile' => $this->publicProfile($profile, false),
        ];
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $threads = 0;
        $posts = 0;
        $pending = 0;
        $profiles = 0;
        $boards = count($this->boardConfig());

        if (is_dir($this->profilesDir)) {
            $profiles = count(glob($this->profilesDir . '/*.json') ?: []);
        }

        foreach ($this->boardConfig() as $boardId => $_meta) {
            $boardDir = $this->dir . '/' . $boardId;
            if (!is_dir($boardDir)) {
                continue;
            }
            foreach (glob($boardDir . '/*.json') ?: [] as $file) {
                if (str_ends_with($file, '_index.json')) {
                    continue;
                }
                $threads++;
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data)) {
                    continue;
                }
                if (empty($data['approved'])) {
                    $pending++;
                }
                foreach ($data['posts'] ?? [] as $post) {
                    $posts++;
                    if (empty($post['approved'])) {
                        $pending++;
                    }
                }
            }
        }

        return [
            'boards' => $boards,
            'threads' => $threads,
            'posts' => $posts,
            'pending' => $pending,
            'profiles' => $profiles,
        ];
    }

    /** @return array<string, mixed> */
    public function moderationQueue(): array
    {
        $queue = [];

        foreach ($this->boardConfig() as $boardId => $_meta) {
            $boardDir = $this->dir . '/' . $boardId;
            if (!is_dir($boardDir)) {
                continue;
            }
            foreach (glob($boardDir . '/*.json') ?: [] as $file) {
                if (str_ends_with($file, '_index.json')) {
                    continue;
                }
                $thread = json_decode((string) file_get_contents($file), true);
                if (!is_array($thread)) {
                    continue;
                }
                $threadId = (string) ($thread['id'] ?? basename($file, '.json'));

                if (empty($thread['approved'])) {
                    $queue[] = [
                        'type' => 'thread',
                        'boardId' => $boardId,
                        'threadId' => $threadId,
                        'title' => (string) ($thread['title'] ?? ''),
                        'author' => (string) ($thread['author'] ?? ''),
                        'authorSlug' => (string) ($thread['authorSlug'] ?? ''),
                        'preview' => $this->previewText((string) (($thread['posts'][0]['body'] ?? '') ?: '')),
                        'created' => (string) ($thread['created'] ?? ''),
                    ];
                }

                foreach ($thread['posts'] ?? [] as $post) {
                    if (!empty($post['approved'])) {
                        continue;
                    }
                    $queue[] = [
                        'type' => 'post',
                        'boardId' => $boardId,
                        'threadId' => $threadId,
                        'postId' => (string) ($post['id'] ?? ''),
                        'title' => (string) ($thread['title'] ?? ''),
                        'author' => (string) ($post['author'] ?? ''),
                        'authorSlug' => (string) ($post['authorSlug'] ?? ''),
                        'preview' => $this->previewText((string) ($post['body'] ?? '')),
                        'created' => (string) ($post['created'] ?? ''),
                    ];
                }
            }
        }

        usort($queue, static fn(array $a, array $b): int => strcmp($b['created'], $a['created']));

        return ['ok' => true, 'count' => count($queue), 'queue' => $queue];
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function moderate(array $payload): array
    {
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $boardId = $this->sanitizeBoardId((string) ($payload['board'] ?? $payload['boardId'] ?? ''));
        $threadId = $this->sanitizeThreadId((string) ($payload['thread'] ?? $payload['threadId'] ?? ''));

        if ($action === 'ban_profile' || $action === 'unban_profile') {
            $slug = $this->sanitizeProfileSlug((string) ($payload['slug'] ?? $payload['authorSlug'] ?? ''));
            $profile = $this->loadProfile($slug);
            if ($profile === null) {
                throw new \InvalidArgumentException('Profile not found.');
            }
            $profile['banned'] = $action === 'ban_profile';
            $this->saveProfile($profile);
            return ['ok' => true, 'message' => $action === 'ban_profile' ? 'Profile banned.' : 'Profile restored.'];
        }

        $thread = $this->loadThread($boardId, $threadId);
        if ($thread === null) {
            throw new \InvalidArgumentException('Thread not found.');
        }

        switch ($action) {
            case 'approve_thread':
                $thread['approved'] = true;
                foreach ($thread['posts'] ?? [] as $i => $post) {
                    if (($post['id'] ?? '') === 'p001') {
                        $thread['posts'][$i]['approved'] = true;
                    }
                }
                break;
            case 'reject_thread':
                $this->deleteThread($boardId, $threadId);
                return ['ok' => true, 'message' => 'Thread rejected and deleted.'];
            case 'approve_post':
                $postId = (string) ($payload['postId'] ?? '');
                $thread = $this->setPostApproved($thread, $postId, true);
                break;
            case 'reject_post':
                $postId = (string) ($payload['postId'] ?? '');
                $thread = $this->removePost($thread, $postId);
                break;
            case 'pin':
            case 'pin_thread':
                $thread['pinned'] = true;
                break;
            case 'unpin':
            case 'unpin_thread':
                $thread['pinned'] = false;
                break;
            case 'lock':
            case 'lock_thread':
                $thread['locked'] = true;
                break;
            case 'unlock':
            case 'unlock_thread':
                $thread['locked'] = false;
                break;
            default:
                throw new \InvalidArgumentException('Unknown moderation action.');
        }

        $this->saveThread($boardId, $thread);
        $this->upsertIndexEntry($boardId, $thread);

        return ['ok' => true, 'message' => 'Moderation applied: ' . $action];
    }

    /** @return array<string, mixed> */
    public function adminListProfiles(): array
    {
        $out = [];
        if (!is_dir($this->profilesDir)) {
            return ['ok' => true, 'profiles' => []];
        }
        foreach (glob($this->profilesDir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $out[] = $this->publicProfile($data, false) + [
                'banned' => !empty($data['banned']),
                'bridged' => !empty($data['bridged']),
                'hasPassword' => !empty($data['passwordHash']),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return ['ok' => true, 'profiles' => $out];
    }

    /** @return array<string, mixed> */
    public function adminBoardsDetail(): array
    {
        require_once __DIR__ . '/ForumzBoardConfig.php';
        $boardConfig = new ForumzBoardConfig($this->grav);
        $yamlBoards = $boardConfig->yamlBoards();

        $boards = [];
        foreach ($this->boardConfig() as $id => $meta) {
            $source = (string) ($meta['source'] ?? 'yaml');
            $inYaml = isset($yamlBoards[$id]);
            $mforumPath = (string) ($meta['path'] ?? '');
            $index = $this->loadIndex($id);
            $threads = array_values(array_filter(
                $index['threads'] ?? [],
                static fn(array $t): bool => !empty($t['approved'])
            ));

            $boards[] = [
                'id' => $id,
                'title' => (string) ($meta['title'] ?? $id),
                'description' => (string) ($meta['description'] ?? ''),
                'postPolicy' => (string) ($meta['post_policy'] ?? 'open'),
                'source' => $inYaml ? 'yaml' : $source,
                'path' => $mforumPath,
                'threadCount' => count($threads),
                'inYaml' => $inYaml,
                'mforumOnly' => $source === 'mforum' && !$inYaml,
            ];
        }

        usort($boards, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

        return ['ok' => true, 'boards' => $boards];
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    public function saveAdminBoard(array $payload): array
    {
        require_once __DIR__ . '/ForumzBoardConfig.php';
        $boardConfig = new ForumzBoardConfig($this->grav);

        $id = $this->sanitizeBoardId((string) ($payload['id'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $postPolicy = strtolower(trim((string) ($payload['postPolicy'] ?? $payload['post_policy'] ?? 'open')));

        if ($title === '') {
            throw new \InvalidArgumentException('Board title is required.');
        }
        if (!in_array($postPolicy, ['open', 'registered', 'moderators'], true)) {
            throw new \InvalidArgumentException('Invalid post policy.');
        }

        $yamlBoards = $boardConfig->yamlBoards();
        $existing = $this->boardConfig();
        $isCreate = !isset($yamlBoards[$id]);
        if (!empty($payload['create']) && isset($existing[$id]) && $isCreate) {
            throw new \InvalidArgumentException('Board id already exists. Pick another id or edit the existing board.');
        }

        $yamlBoards[$id] = [
            'title' => $title,
            'description' => $description,
            'post_policy' => $postPolicy,
        ];
        $boardConfig->saveBoards($yamlBoards);
        $this->ensureBoardDir($id);
        $this->saveIndex($id, $this->loadIndex($id));

        return [
            'ok' => true,
            'message' => $isCreate ? 'Board created.' : 'Board updated.',
            'board' => [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'postPolicy' => $postPolicy,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function deleteAdminBoard(string $boardId, bool $deleteData = false, bool $deleteMforumFile = false): array
    {
        require_once __DIR__ . '/ForumzBoardConfig.php';
        require_once __DIR__ . '/ForumzBoardScanner.php';

        $boardId = $this->sanitizeBoardId($boardId);
        $merged = $this->boardConfig();
        if (!isset($merged[$boardId])) {
            throw new \InvalidArgumentException('Board not found.');
        }

        $meta = $merged[$boardId];
        $boardConfig = new ForumzBoardConfig($this->grav);
        $yamlBoards = $boardConfig->yamlBoards();
        $notes = [];

        if (isset($yamlBoards[$boardId])) {
            unset($yamlBoards[$boardId]);
            $boardConfig->saveBoards($yamlBoards);
            $notes[] = 'Removed from forumz.yaml';
        }

        $mforumPath = (string) ($meta['path'] ?? '');
        if ($deleteMforumFile && $mforumPath !== '') {
            $file = GRAV_ROOT . '/' . ltrim(str_replace('\\', '/', $mforumPath), '/');
            if (is_file($file)) {
                if (!@unlink($file)) {
                    throw new \RuntimeException('Could not delete .mforum file.');
                }
                $notes[] = 'Deleted .mforum file';
            }
        }

        if ($notes === []) {
            throw new \InvalidArgumentException('Nothing to delete — board is not in forumz.yaml. Enable “Delete .mforum file” or add it to config first.');
        }

        if ($deleteData) {
            $this->deleteBoardData($boardId);
            $notes[] = 'Thread data removed';
        }

        $scanned = ForumzBoardScanner::scan($this->grav);
        $stillExists = isset($scanned[$boardId]) || isset($boardConfig->yamlBoards()[$boardId]);
        if ($stillExists) {
            $notes[] = 'Board still defined elsewhere (.mforum scan)';
        }

        return ['ok' => true, 'message' => implode('. ', $notes) . '.'];
    }

    private function deleteBoardData(string $boardId): void
    {
        $dir = $this->dir . '/' . $boardId;
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    public function sessionSlug(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            return null;
        }
        $path = $this->sessionsDir . '/' . $token . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $expires = strtotime((string) ($data['expires'] ?? ''));
        if ($expires !== false && $expires < time()) {
            @unlink($path);
            return null;
        }
        $slug = (string) ($data['slug'] ?? '');
        return $slug !== '' ? $slug : null;
    }

    public function createSession(string $slug): string
    {
        if (!is_dir($this->sessionsDir)) {
            mkdir($this->sessionsDir, 0755, true);
        }
        $token = bin2hex(random_bytes(32));
        $payload = [
            'slug' => $slug,
            'created' => gmdate('c'),
            'expires' => gmdate('c', time() + 86400 * 30),
        ];
        file_put_contents(
            $this->sessionsDir . '/' . $token . '.json',
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
        return $token;
    }

    public function destroySession(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            return;
        }
        $path = $this->sessionsDir . '/' . $token . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $payload */
    /** @return array{display: string, slug: string} */
    private function resolveAuthor(array $payload, ?string $authorSlug): array
    {
        if ($authorSlug !== null && $authorSlug !== '') {
            $profile = $this->loadProfile($authorSlug);
            if ($profile !== null && empty($profile['banned'])) {
                return [
                    'display' => (string) ($profile['displayName'] ?? $authorSlug),
                    'slug' => $authorSlug,
                ];
            }
        }

        $guest = $this->sanitizeName((string) ($payload['author'] ?? $payload['name'] ?? 'Anonymous gravver'));
        return ['display' => $guest, 'slug' => ''];
    }

    /** @return string profile slug */
    private function ensureBridgedProfile(\Grav\Common\User\Interfaces\UserInterface $user): string
    {
        $gravUsername = ForumzMambersBridge::gravUsername($user);
        $slug = strtolower(preg_replace('/[^a-z0-9_-]/', '', $gravUsername) ?? '');
        if ($slug === '' || strlen($slug) < 3) {
            $slug = 'user-' . substr(md5($gravUsername), 0, 8);
        }
        $slug = substr($slug, 0, 32);

        $display = ForumzMambersBridge::gravDisplayName($user);
        if ($display === '') {
            $display = $slug;
        }

        $avatar = ForumzMambersBridge::gravAvatar($user);
        $existing = $this->loadProfile($slug);
        if ($existing === null) {
            $profile = [
                'slug' => $slug,
                'displayName' => $this->sanitizeName($display),
                'bio' => '',
                'avatar' => $avatar,
                'badges' => ['gravver'],
                'stats' => ['threads' => 0, 'posts' => 0],
                'created' => gmdate('c'),
                'bridged' => true,
                'gravUsername' => $gravUsername,
            ];
            $this->saveProfile($profile);
            return $slug;
        }

        $existing['displayName'] = $this->sanitizeName($display);
        $existing['avatar'] = $avatar;
        $existing['bridged'] = true;
        $existing['gravUsername'] = $gravUsername;
        $this->saveProfile($existing);

        return $slug;
    }

    /** @param list<array<string, mixed>> $posts */
    /** @return list<array<string, mixed>> */
    private function enrichPosts(array $posts): array
    {
        $out = [];
        foreach ($posts as $post) {
            $slug = (string) ($post['authorSlug'] ?? '');
            if ($slug !== '') {
                $profile = $this->loadProfile($slug);
                if ($profile !== null && empty($profile['banned'])) {
                    $post['authorAvatar'] = (string) ($profile['avatar'] ?? '🪐');
                    $post['authorBadges'] = $profile['badges'] ?? [];
                }
            }
            $out[] = $post;
        }
        return $out;
    }

    /** @param array<string, mixed> $profile */
    /** @return array<string, mixed> */
    private function publicProfile(array $profile, bool $includeEmail): array
    {
        $out = [
            'slug' => (string) ($profile['slug'] ?? ''),
            'displayName' => (string) ($profile['displayName'] ?? ''),
            'bio' => (string) ($profile['bio'] ?? ''),
            'avatar' => (string) ($profile['avatar'] ?? '🪐'),
            'joined' => (string) ($profile['joined'] ?? ''),
            'badges' => array_values($profile['badges'] ?? []),
            'stats' => [
                'threads' => (int) ($profile['stats']['threads'] ?? 0),
                'posts' => (int) ($profile['stats']['posts'] ?? 0),
            ],
        ];
        if ($includeEmail && !empty($profile['email'])) {
            $out['email'] = (string) $profile['email'];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function loadProfile(string $slug): ?array
    {
        $path = $this->profilesDir . '/' . $slug . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $profile */
    private function saveProfile(array $profile): void
    {
        if (!is_dir($this->profilesDir)) {
            mkdir($this->profilesDir, 0755, true);
        }
        $slug = (string) ($profile['slug'] ?? '');
        $payload = json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Could not encode profile.');
        }
        file_put_contents($this->profilesDir . '/' . $slug . '.json', $payload . "\n", LOCK_EX);
    }

    private function bumpProfileStats(string $slug, bool $thread, bool $post): void
    {
        $profile = $this->loadProfile($slug);
        if ($profile === null) {
            return;
        }
        if (!isset($profile['stats']) || !is_array($profile['stats'])) {
            $profile['stats'] = ['threads' => 0, 'posts' => 0];
        }
        if ($thread) {
            $profile['stats']['threads'] = (int) ($profile['stats']['threads'] ?? 0) + 1;
        }
        if ($post) {
            $profile['stats']['posts'] = (int) ($profile['stats']['posts'] ?? 0) + 1;
        }
        $this->saveProfile($profile);
    }

    /** @return array<string, mixed> */
    private function loadIndex(string $boardId): array
    {
        $path = $this->indexPath($boardId);
        if (!is_file($path)) {
            return ['boardId' => $boardId, 'threads' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : ['boardId' => $boardId, 'threads' => []];
    }

    /** @param array<string, mixed> $thread */
    private function upsertIndexEntry(string $boardId, array $thread): void
    {
        $index = $this->loadIndex($boardId);
        $threads = $index['threads'] ?? [];
        $approvedPosts = array_values(array_filter(
            $thread['posts'] ?? [],
            static fn(array $p): bool => !empty($p['approved'])
        ));
        $entry = [
            'id' => (string) $thread['id'],
            'title' => (string) $thread['title'],
            'author' => (string) ($thread['author'] ?? ''),
            'authorSlug' => (string) ($thread['authorSlug'] ?? ''),
            'updated' => (string) ($thread['updated'] ?? gmdate('c')),
            'replyCount' => max(0, count($approvedPosts) - 1),
            'pinned' => !empty($thread['pinned']),
            'approved' => !empty($thread['approved']),
            'locked' => !empty($thread['locked']),
        ];

        $found = false;
        foreach ($threads as $i => $row) {
            if (($row['id'] ?? '') === $entry['id']) {
                $threads[$i] = $entry;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $threads[] = $entry;
        }

        $index['boardId'] = $boardId;
        $index['threads'] = $threads;
        $this->saveIndex($boardId, $index);
    }

    /** @param array<string, mixed> $index */
    private function saveIndex(string $boardId, array $index): void
    {
        $this->ensureBoardDir($boardId);
        file_put_contents(
            $this->indexPath($boardId),
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    /** @return array<string, mixed>|null */
    private function loadThread(string $boardId, string $threadId): ?array
    {
        $path = $this->threadPath($boardId, $threadId);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $thread */
    private function saveThread(string $boardId, array $thread): void
    {
        $this->ensureBoardDir($boardId);
        file_put_contents(
            $this->threadPath($boardId, (string) $thread['id']),
            json_encode($thread, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    private function deleteThread(string $boardId, string $threadId): void
    {
        $path = $this->threadPath($boardId, $threadId);
        if (is_file($path)) {
            @unlink($path);
        }
        $index = $this->loadIndex($boardId);
        $index['threads'] = array_values(array_filter(
            $index['threads'] ?? [],
            static fn(array $t): bool => ($t['id'] ?? '') !== $threadId
        ));
        $this->saveIndex($boardId, $index);
    }

    /** @param array<string, mixed> $thread */
    /** @return array<string, mixed> */
    private function setPostApproved(array $thread, string $postId, bool $approved): array
    {
        foreach ($thread['posts'] ?? [] as $i => $post) {
            if (($post['id'] ?? '') === $postId) {
                $thread['posts'][$i]['approved'] = $approved;
                if ($postId === 'p001') {
                    $thread['approved'] = $approved;
                }
                return $thread;
            }
        }
        throw new \InvalidArgumentException('Post not found.');
    }

    /** @param array<string, mixed> $thread */
    /** @return array<string, mixed> */
    private function removePost(array $thread, string $postId): array
    {
        $posts = $thread['posts'] ?? [];
        $thread['posts'] = array_values(array_filter($posts, static fn(array $p): bool => ($p['id'] ?? '') !== $postId));
        if ($postId === 'p001' || count($thread['posts']) === 0) {
            $this->deleteThread((string) $thread['boardId'], (string) $thread['id']);
            return $thread;
        }
        return $thread;
    }

    private function ensureBoardDir(string $boardId): void
    {
        $dir = $this->dir . '/' . $boardId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function indexPath(string $boardId): string
    {
        return $this->dir . '/' . $boardId . '/_index.json';
    }

    private function threadPath(string $boardId, string $threadId): string
    {
        return $this->dir . '/' . $boardId . '/' . $threadId . '.json';
    }

    private function uniqueThreadId(string $title): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? 'thread', '-'), '-');
        if ($base === '') {
            $base = 'thread';
        }
        return substr($base, 0, 48) . '-' . bin2hex(random_bytes(3));
    }

    private function previewText(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        if (strlen($body) > 120) {
            return substr($body, 0, 117) . '…';
        }
        return $body;
    }

    private function assertBoardExists(string $boardId): void
    {
        if (!isset($this->boardConfig()[$boardId])) {
            throw new \InvalidArgumentException('Unknown board.');
        }
    }

    private function assertCanPost(string $boardId, ?string $authorSlug): void
    {
        $policy = (string) ($this->boardConfig()[$boardId]['post_policy'] ?? 'open');
        if ($policy === 'open') {
            return;
        }
        if ($policy === 'registered' && $authorSlug !== null && $authorSlug !== '') {
            return;
        }
        if ($policy === 'registered') {
            throw new \InvalidArgumentException('Login required — register a Forumz profile first.');
        }
        throw new \InvalidArgumentException('Moderators-only board.');
    }

    private function sanitizeBoardId(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
            throw new \InvalidArgumentException('Invalid board id.');
        }
        return $id;
    }

    private function sanitizeThreadId(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9_-]{1,64}$/', $id)) {
            throw new \InvalidArgumentException('Invalid thread id.');
        }
        return $id;
    }

    private function sanitizeProfileSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9_-]{3,32}$/', $slug)) {
            throw new \InvalidArgumentException('Slug required (3–32 chars, a-z 0-9 - _).');
        }
        return $slug;
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || strlen($name) > 80) {
            throw new \InvalidArgumentException('Name required (max 80 chars).');
        }
        return $name;
    }

    private function sanitizeTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        if ($title === '' || strlen($title) > 160) {
            throw new \InvalidArgumentException('Thread title required (max 160 chars).');
        }
        return $title;
    }

    private function sanitizeBody(string $body): string
    {
        $body = trim($body);
        if ($body === '' || strlen($body) > 8000) {
            throw new \InvalidArgumentException('Post body required (max 8000 chars).');
        }
        return $body;
    }

    private function sanitizeBio(string $bio): string
    {
        $bio = trim($bio);
        if (strlen($bio) > 500) {
            throw new \InvalidArgumentException('Bio max 500 chars.');
        }
        return $bio;
    }

    private function sanitizeAvatar(string $avatar): string
    {
        $avatar = trim($avatar);
        if ($avatar === '') {
            return '🪐';
        }
        if (strlen($avatar) > 8) {
            return mb_substr($avatar, 0, 2);
        }
        return $avatar;
    }
}
