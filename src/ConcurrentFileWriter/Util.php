<?php

namespace ConcurrentFileWriter;

class Util
{
    /**
     * It is a tiny wrapper on top of mkdir, makes sure that it working with the desired
     * permissions and it is recursive.
     */
    public static function mkdir($path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0744, true);
        }
    }

    /**
     * Deletes a file or directory recursively.
     */
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
