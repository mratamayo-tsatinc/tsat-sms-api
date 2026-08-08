<?php

spl_autoload_register(function (string $class) {
    $base = __DIR__ . '/../';

    // Map "App\..." namespace to lowercase "app/" directory
    $file = $base . str_replace(['App\\', '\\'], ['app/', '/'], $class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
