<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path==='/openapi.json') { return false; }
if (is_string($path) && preg_match('#^/assets/[a-zA-Z0-9._/-]+$#D', $path) && !str_contains($path,'..') && is_file(__DIR__.$path)) {
    return false;
}
require __DIR__.'/index.php';
