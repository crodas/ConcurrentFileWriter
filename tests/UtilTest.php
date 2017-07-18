<?php

use PHPUnit\Framework\TestCase;

use ConcurrentFileWriter\Util;

class UtilTest extends TestCase
{
    public function testDeleteOnNothing()
    {
        $this->assertFalse(Util::delete('foo'));
    }

    public function testCreateFolder()
    {
        Util::mkdir(__DIR__ . '/tmp/foo/bar/xxx');
        touch(__DIR__ .'/tmp/xxx');
        $this->assertTrue(is_dir(__DIR__ . '/tmp/foo/bar/xxx'));
        $this->assertTrue(is_file(__DIR__ . '/tmp/xxx'));
    }

    /**
     * @dependsOn testCreateFolder
     */
    public function testDeleteFile()
    {
        $this->assertTrue(is_file(__DIR__ . '/tmp/xxx'));
        $this->assertTrue(Util::delete(__DIR__ . '/tmp/xxx'));
        $this->assertFalse(is_file(__DIR__ . '/tmp/xxx'));
    }

    /**
     * @dependsOn testDeleteFile
     */
    public function testRecursiveDelete()
    {
        $this->assertTrue(Util::delete(__DIR__ . '/tmp'));
        $this->assertFalse(is_dir(__DIR__ . '/tmp/foo/bar/xxx'));
    }

}
