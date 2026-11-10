<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Panel\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
