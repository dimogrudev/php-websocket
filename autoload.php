<?php

spl_autoload_register(function ($className) {
    $fileName = preg_replace(['/[^a-zA-Z\\\\]/', '/\\\\/'], ['', '/'], $className);
    $filePath = __DIR__ . '/' . $fileName . '.php';

    if (file_exists($filePath)) {
        require $filePath;
        return true;
    }

    return false;
});
