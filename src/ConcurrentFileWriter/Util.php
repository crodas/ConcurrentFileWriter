<?php

namespace ConcurrentFileWriter;

class Util
{
    public static function mkdir($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0744, true);
        }
    }

    public static function delete($path)
    {
        if (!is_readable($path)) {
            return false;
        }

        if (!is_dir($path)) {
            unlink($path);
            return true;
        }

        foreach (scandir($path) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $file = $path . '/' . $file;
            if (is_dir($file)) {
                self::delete($file);
            } else {
                unlink($file);
            }
        }

        rmdir($path);

        return true;
    }
}
