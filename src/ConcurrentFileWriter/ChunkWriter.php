<?php

namespace ConcurrentFileWriter;

use RuntimeException;

class ChunkWriter
{
    protected $commited = false;
    protected $file;
    protected $tmp;
    protected $fp;

    public function __construct($file, $tmp = NULL)
    {
        $this->file = $file;
        $this->tmp  = tempnam($tmp ?: sys_get_temp_dir(), 'tmp');
        $this->fp   = fopen($this->tmp, 'w');
    }

    public function commit()
    {
        if ($this->commited) {
            return;
        }

        $this->commited = true;

        fflush($this->fp);
        fclose($this->fp);
        if (!@rename($this->tmp, $this->file)) {
            throw new RuntimeException("Cannot move the temporary file");
        }
        return $this->file;
    }

    public function getStream()
    {
        $this->checkIsUncommitted();
        return $this->fp;
    }

    public function write($content)
    {
        $this->checkIsUnCommitted();
        fwrite($this->fp, $content);
    }

    function checkIsUnCommitted()
    {
        if ($this->commited) {
            throw new RuntimeException("Cannot perform any write modifications because the " . __CLASS__ . " object is committed");
        }
    }

    public function __destruct()
    {
        if ($this->file && $this->tmp) {
            $this->commit();
        }
    }
}
