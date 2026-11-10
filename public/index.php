<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
(new Panel\Http\Kernel())->handle();
