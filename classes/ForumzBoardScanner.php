<?php

declare(strict_types=1);

namespace Grav\Plugin\Forumz;

use Grav\Common\Grav;
use Symfony\Component\Yaml\Yaml;

/**
 * Scan user/pages for .mforum board definition files (MUD Forum Lite spec).
 */
class ForumzBoardScanner
{
    /** @return array<string, array<string, mixed>> board id => meta */
    public static function scan(Grav $grav): array
    {
        $cfg = (array) $grav['config']->get('plugins.forumz', []);
        if (($cfg['scan_mforum_boards'] ?? true) === false) {
            return [];
        }

        $root = GRAV_ROOT . '/user/pages';
        if (!is_dir($root)) {
            return [];
        }

        $boards = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'mforum') {
                continue;
            }

            $parsed = self::parseFile($file->getPathname());
            if ($parsed === null) {
                continue;
            }

            $id = (string) ($parsed['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $boards[$id] = $parsed;
        }

        ksort($boards);

        return $boards;
    }

    /** @return array<string, mixed>|null */
    private static function parseFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $meta = [];
        $intro = '';

        if (preg_match('/^---\s*\r?\n(.*?)\r?\n---\s*\r?\n?(.*)\z/s', $raw, $matches)) {
            try {
                $parsed = Yaml::parse($matches[1]);
                $meta = is_array($parsed) ? $parsed : [];
            } catch (\Throwable) {
                return null;
            }
            $intro = trim((string) ($matches[2] ?? ''));
        }

        $forum = is_array($meta['forum'] ?? null) ? $meta['forum'] : [];
        $id = (string) ($forum['id'] ?? $forum['slug'] ?? '');
        if ($id === '') {
            $basename = pathinfo($path, PATHINFO_FILENAME);
            $id = strtolower(preg_replace('/[^a-z0-9_-]/', '', $basename) ?? '');
        }

        if ($id === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
            return null;
        }

        $title = (string) ($meta['title'] ?? $forum['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $id)));
        $description = (string) ($forum['description'] ?? '');
        $postPolicy = (string) ($forum['post_policy'] ?? 'registered');
        if (!in_array($postPolicy, ['open', 'registered', 'moderators'], true)) {
            $postPolicy = 'registered';
        }

        $threadSort = (string) ($forum['thread_sort'] ?? 'updated');
        if (!in_array($threadSort, ['updated', 'created', 'replies'], true)) {
            $threadSort = 'updated';
        }

        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'post_policy' => $postPolicy,
            'thread_sort' => $threadSort,
            'intro' => $intro,
            'source' => 'mforum',
            'path' => str_replace('\\', '/', str_replace(GRAV_ROOT . '/', '', $path)),
        ];
    }
}
