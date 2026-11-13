<?php
declare(strict_types=1);

/**
 * Admin entry point (thin shim).
 *
 * All admin screens now live in App\Http\AdminController. This file keeps the
 * legacy `admin/index.php?view=…` URLs working while delegating the work to
 * the new MVC layer.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

\App\Core\Response::securityHeaders();

$controller = new \App\Http\AdminController();
echo $controller->handle();
