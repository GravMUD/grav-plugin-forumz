<?php

declare(strict_types=1);

namespace Grav\Plugin\Forumz;

use Grav\Common\Grav;
use Symfony\Component\Yaml\Yaml;

/**
 * Read/write board definitions in user/config/plugins/forumz.yaml
 */
class ForumzBoardConfig
{
    private Grav $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    public function configPath(): string
    {
        $path = $this->grav['locator']->findResource('user://config/plugins/forumz.yaml', true, true);
        if (!is_string($path) || $path === '') {
            $path = GRAV_ROOT . '/user/config/plugins/forumz.yaml';
        }

        return $path;
    }

    /** @return array<string, mixed> */
    public function readFileConfig(): array
    {
        $path = $this->configPath();
        if (!is_file($path)) {
            return ['enabled' => false, 'boards' => []];
        }

        try {
            $parsed = Yaml::parse((string) file_get_contents($path));
        } catch (\Throwable) {
            return ['enabled' => false, 'boards' => []];
        }

        return is_array($parsed) ? $parsed : ['enabled' => false, 'boards' => []];
    }

    /** @return array<string, array<string, mixed>> */
    public function yamlBoards(): array
    {
        $boards = $this->readFileConfig()['boards'] ?? [];
        if (!is_array($boards)) {
            return [];
        }

        $out = [];
        foreach ($boards as $id => $meta) {
            if (!is_string($id) || !is_array($meta)) {
                continue;
            }
            $out[$id] = $meta;
        }

        return $out;
    }

    /** @param array<string, array<string, mixed>> $boards */
    public function saveBoards(array $boards): void
    {
        $path = $this->configPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $config = $this->readFileConfig();
        $config['boards'] = $boards;

        $yaml = Yaml::dump($config, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        if (file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write forumz.yaml');
        }

        $this->syncRuntimeConfig($config);
    }

    /** @param array<string, mixed> $config */
    public function syncRuntimeConfig(array $config): void
    {
        $current = (array) $this->grav['config']->get('plugins.forumz', []);
        $this->grav['config']->set('plugins.forumz', array_merge($current, $config));
    }
}
