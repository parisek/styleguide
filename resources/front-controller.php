<?php

declare(strict_types=1);

// Styleguide front controller, from parisek/styleguide
// (resources/front-controller.php). Write or refresh it with
// `vendor/bin/styleguide front-controller:init`.
//
// The catalogue is configured in styleguide.yaml beside this file. The kernel
// lives in the package; this file does the two things a package cannot do for
// itself: find the Composer autoloader, and hand static files back to PHP's
// built-in server.

// Walk up for the autoloader. vendor/ can sit beside this directory, inside it,
// or at the root of a CMS project several levels up.
$styleguideRoot = __DIR__;
while (!is_file($styleguideRoot . '/vendor/autoload.php')) {
	if ($styleguideRoot === dirname($styleguideRoot)) {
		error_log(sprintf('styleguide: no vendor/autoload.php in %s or any directory above it', __DIR__));
		http_response_code(500);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Composer dependencies are missing. Run `composer install`. The server log names the directory searched.';
		return;
	}
	$styleguideRoot = dirname($styleguideRoot);
}
require $styleguideRoot . '/vendor/autoload.php';

// PHP's built-in server only: let it send dist assets, images and fonts itself.
if (\Parisek\Styleguide\Bridge\Symfony\FrontController::isBuiltInServerFile(__DIR__)) {
	return false;
}

\Parisek\Styleguide\Bridge\Symfony\FrontController::run(__DIR__);
