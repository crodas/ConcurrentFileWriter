<?php

use PHPUnit\Framework\TestCase;

use ConcurrentFileWriter\ConcurrentFileWriter;
use ConcurrentFileWriter\ChunkWriter;

class BasicTest extends TestCase
{
    public function testDoNotCreateTwice()
    {
        $file = 'files/' . uniqid(true) . '.txt';
        $x = new ConcurrentFileWriter($file);
        $this->assertTrue($x->create());

        $y = new ConcurrentFileWriter($file);
        $this->assertFalse($y->create());
    }

    public function testCopyInOrder()
    {
        $file = 'files/' . uniqid(true) . '.txt';
        $x = new ConcurrentFileWriter($file);
        $this->assertTrue($x->create());

        $source = createRandomFile(10 * 1024 * 1024);

        $blocksize = 1024 * 1024;
        $times = 0;

        $fp = fopen($source, 'r');
        while (!feof($fp)) {
            $x->write(ftell($fp), $fp, $blocksize);
            ++$times;
        }
        fclose($fp);
        $x->finalize();

        $this->assertEquals(hash_file('md5', $source), hash_file('md5', $file));
        $this->assertEquals(hash_file('sha1', $source), hash_file('sha1', $file));
        $this->assertEquals(hash_file('sha256', $source), hash_file('sha256', $file));
    }

    /**
     * @expectedException RuntimeException
     */
    public function testFinalizeTwice()
    {
        $file = 'files/' . uniqid(true) . '.txt';
        $x = new ConcurrentFileWriter($file);
        $this->assertTrue($x->create());

        $source = createRandomFile(10 * 1024 * 1024);

        $blocksize = 1024 * 1024;
        $times = 0;
        $fp = fopen($source, 'r');
        while (!feof($fp)) {
            $x->write(ftell($fp), $fp, $blocksize);
            ++$times;
        }
        fclose($fp);
        $x->finalize();
        $x->finalize();
    }

    /**
     * @expectedException RuntimeException
     */
    public function testInvalidWriteCall()
    {
        $x = new ConcurrentFileWriter(__FILE__);
        $this->assertFalse($x->create());

        $x->write(0, 'hi there');
    }

    public function testWriteString()
    {
        $x = new ConcurrentFileWriter('files/str');
        $x->create();
        $x->write(0, 'hi there', 3);
        $chunk = $x->write(3, 'here');
        $this->assertTrue($chunk instanceof ChunkWriter);
        $this->assertRegexp('@/3$@', $chunk->getFileName());
        $x->finalize();
        
        $this->assertEquals('hi here', file_get_contents('files/str'));
    }

    public function testRandomOrderWrite()
    {
        $x = new ConcurrentFileWriter('files/str2');
        $x->create();
        $x->write(3, 'here');
        $x->write(0, 'hi there', 3);
        $x->finalize();
        
        $this->assertEquals('hi here', file_get_contents('files/str2'));
    }

    /**
     * @expectedException RuntimeException
     */
    public function testMissingChunk()
    {
        $x = new ConcurrentFileWriter('files/str3');
        $x->create();
        $x->write(0, 'hi there', 3);
        $x->write(50, 'here');
        $this->assertEquals(array(
            array('offset' => 3, 'size' => 47),
        ), $x->getMissingChunks());

        $x->finalize();
    }

    /**
     * @expectedException RuntimeException
     */
    public function testMissingChunkBeginning()
    {
        $x = new ConcurrentFileWriter('files/str4');
        $x->create();
        $x->write(50, 'here');
        $this->assertEquals(array(
            array('offset' => 0, 'size' => 50),
        ), $x->getMissingChunks());

        $x->finalize();
    }

    public function testPrepareForConcurrentFinalize()
    {
        $x = new ConcurrentFileWriter('files/str6');
        $this->assertTrue($x->create());
        $x->write(0, str_repeat('h', 50));

        $lock = fopen( 'files/str6.part/.lock', 'r+');
        $this->assertTrue(flock( $lock, LOCK_EX | LOCK_NB ) );
        $this->assertFalse($x->finalize());
    }

    /**
     * @expectedException RuntimeException
     */
    public function testChunkWriterPermissionException()
    {
        $x =new ChunkWriter('/root/tmp');
        $x->write('hi');
        $x->commit();
    }

    /**
     * @expectedException RuntimeException
     */
    public function testChunkWriterDobleCommit()
    {
        $x = new ChunkWriter('files/tmp');
        $x->write('hi');
        $x->commit();

        $this->assertEquals('hi', file_get_contents('files/tmp'));

        $x->write('more text');
    }
}
