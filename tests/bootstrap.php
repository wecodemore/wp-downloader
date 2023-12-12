<?php


declare(strict_types=1);

//phpcs:disable PSR1.Files.SideEffects

$libraryPath = dirname(__DIR__, 1);
$vendorPath = "{$libraryPath}/vendor";
if (!realpath($vendorPath)) {
    die('Please install via Composer before running tests.');
}

putenv('LIBRARY_PATH=' . $libraryPath);

if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', "{$vendorPath}/autoload.php");
}


require_once "{$vendorPath}/antecedent/patchwork/Patchwork.php";
require_once "{$vendorPath}/autoload.php";

unset($libraryPath, $vendorPath);

//phpcs:enable
