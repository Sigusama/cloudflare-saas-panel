<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

final class Config
{
    public static function storage(string $path = ''): string
    {
        return (getenv('PANEL_STORAGE') ?: BASE_PATH . '/storage') . ($path !== '' ? '/' . $path : '');
    }

    public static function installed(): bool
    {
        return is_file(self::storage('config.php')) && is_file(self::storage('installed.lock'));
    }

    public static function read(): array
    {
        if (!is_file(self::storage('config.php'))) {
            throw new AppException('请先完成安装', 503, 'not_installed');
        }
        return require self::storage('config.php');
    }

    public static function atomicWrite(string $path, string $value): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $value, LOCK_EX) === false) {
            throw new AppException('无法写入私有配置目录', 500);
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new AppException('无法保存配置文件', 500);
        }
    }
}
