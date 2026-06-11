<?php

declare(strict_types=1);

namespace Grav\Plugin\Forumz;

/**
 * Forumz fences — embed boards, threads, profiles in .mud pages when grav-mud-alpha is installed.
 * Registered via onMudFenceRender from forumz.
 */
class ForumzFences
{
    /** @param array<string, mixed> $node */
    public static function render(string $type, array $node, array $attrs, string $body, array $data): ?string
    {
        $api = self::apiBase($attrs, $data);

        return match ($type) {
            'forum', 'forumz' => self::renderBoard($attrs, $data, $api),
            'forum-thread', 'forum-thread-view' => self::renderThread($attrs, $data, $api),
            'forum-profile' => self::renderProfile($attrs, $data, $api),
            'forum-profiles' => self::renderProfiles($attrs, $data, $api),
            default => null,
        };
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderBoard(array $attrs, array $data, string $api): string
    {
        $board = (string) ($attrs['board'] ?? $data['board'] ?? 'general');
        $limit = (string) ($attrs['limit'] ?? $data['limit'] ?? '20');
        $extra = self::dataAttrs($data);
        if (!empty($attrs['hide_header']) || !empty($data['hide_header'])) {
            $extra .= ' data-hide-header="1"';
        }

        return '<section class="forumz-wrap forumz-wrap--board"><div class="forumz" data-forumz data-mode="board" data-board="'
            . self::esc($board) . '" data-limit="' . self::esc($limit)
            . '" data-api="' . self::esc($api) . '"' . $extra
            . '><p class="forumz-loading">Loading Forumz…</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderThread(array $attrs, array $data, string $api): string
    {
        $board = (string) ($attrs['board'] ?? $data['board'] ?? 'general');
        $thread = (string) ($attrs['thread'] ?? $data['thread'] ?? '');

        return '<section class="forumz-wrap"><div class="forumz" data-forumz data-mode="thread" data-board="'
            . self::esc($board) . '" data-thread="' . self::esc($thread)
            . '" data-api="' . self::esc($api) . '"><p class="forumz-loading">Loading thread…</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderProfile(array $attrs, array $data, string $api): string
    {
        $user = (string) ($attrs['user'] ?? $attrs['slug'] ?? $data['user'] ?? $data['slug'] ?? '');

        return '<section class="forumz-wrap"><div class="forumz" data-forumz data-mode="profile" data-user="'
            . self::esc($user) . '" data-api="' . self::esc($api) . '"><p class="forumz-loading">Loading profile…</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderProfiles(array $attrs, array $data, string $api): string
    {
        $limit = (string) ($attrs['limit'] ?? $data['limit'] ?? '12');
        $title = (string) ($attrs['title'] ?? $data['title'] ?? '');

        return '<section class="forumz-wrap forumz-wrap--profiles"><div class="forumz" data-forumz data-mode="profiles" data-limit="'
            . self::esc($limit) . '" data-title="' . self::esc($title)
            . '" data-api="' . self::esc($api) . '"><p class="forumz-loading">Loading gravvers…</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function apiBase(array $attrs, array $data): string
    {
        $api = trim((string) ($attrs['api'] ?? $data['api'] ?? '/api/forumz'));

        return '/' . trim($api, '/');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string, mixed> $data */
    private static function dataAttrs(array $data): string
    {
        $keys = ['mambers_auth', 'login_url', 'register_url', 'members_url'];
        $out = '';
        foreach ($keys as $key) {
            if (!isset($data[$key]) || (string) $data[$key] === '') {
                continue;
            }
            $out .= ' data-' . self::esc(str_replace('_', '-', $key)) . '="' . self::esc((string) $data[$key]) . '"';
        }

        return $out;
    }
}
