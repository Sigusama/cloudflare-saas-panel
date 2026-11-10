<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

final class Version
{
    public static function current(): string
    {
        return trim((string)file_get_contents(BASE_PATH.'/VERSION'));
    }
}
