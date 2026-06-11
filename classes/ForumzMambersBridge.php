<?php

declare(strict_types=1);

namespace Grav\Plugin\Forumz;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * Optional Mambers / Grav Login identity bridge for Forumz registered boards.
 */
class ForumzMambersBridge
{
    public static function isInstalled(Grav $grav): bool
    {
        if (class_exists(\Grav\Plugin\MambersPlugin::class)) {
            return true;
        }

        $locator = $grav['locator'];
        $mambers = $locator->findResource('plugins://mambers', true);
        if (is_string($mambers) && is_dir($mambers)) {
            return true;
        }

        $legacy = $locator->findResource('plugins://grav-mud-mambers', true);

        return is_string($legacy) && is_dir($legacy);
    }

    public static function isEnabled(Grav $grav): bool
    {
        if (!self::isInstalled($grav)) {
            return false;
        }

        $config = $grav['config'];
        if ($config->get('plugins.mambers.enabled') === false) {
            return false;
        }
        if ($config->get('plugins.grav-mud-mambers.enabled') === false) {
            return false;
        }

        return true;
    }

    public static function identityBridgeEnabled(Grav $grav): bool
    {
        $forumCfg = (array) $grav['config']->get('plugins.forumz', []);
        if (($forumCfg['mambers_identity_bridge'] ?? true) === false) {
            return false;
        }

        if (!self::isEnabled($grav)) {
            return false;
        }

        $mambersCfg = self::mambersConfig($grav);

        return ($mambersCfg['forumz_identity_bridge'] ?? true) !== false;
    }

    public static function siteUser(Grav $grav): ?UserInterface
    {
        if (!self::identityBridgeEnabled($grav)) {
            return null;
        }

        $user = $grav['user'] ?? null;
        if (!$user instanceof UserInterface || !$user->exists()) {
            return null;
        }

        if (self::gravUsername($user) === '') {
            return null;
        }

        if (method_exists($user, 'authorize') && !$user->authorize('site.login')) {
            return null;
        }

        return $user;
    }

    public static function gravUsername(UserInterface $user): string
    {
        return trim((string) ($user->get('username') ?? ''));
    }

    public static function gravDisplayName(UserInterface $user): string
    {
        $full = trim((string) ($user->get('fullname') ?? ''));

        return $full !== '' ? $full : self::gravUsername($user);
    }

    public static function gravAvatar(UserInterface $user): string
    {
        $stored = $user->get('avatar');
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        if (method_exists($user, 'getAvatarUrl')) {
            $url = trim((string) $user->getAvatarUrl());
            if ($url !== '') {
                return $url;
            }
        }

        return '🪐';
    }

    /** @return array<string, mixed> */
    private static function mambersConfig(Grav $grav): array
    {
        $cfg = (array) $grav['config']->get('plugins.mambers', []);
        if ($cfg !== []) {
            return $cfg;
        }

        return (array) $grav['config']->get('plugins.grav-mud-mambers', []);
    }
}
