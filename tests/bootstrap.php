<?php

require __DIR__ . '/../vendor/autoload.php';

use ConcurrentFileWriter\Util;

function createRandomFile($size) {
    $file = tempnam('files/', 'tmp');
    $fp = fopen($file, 'a+');

    for ($i = 0; $i < $size; ) {
        $len = min(64*1024, $size - $i);
        fwrite($fp, random_bytes($len));
        $i += $len;
    }
    fclose($fp);

    return $file;
}

Util::delete('files/');
