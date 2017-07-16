<?php

namespace ConcurrentFileWriter;

use RuntimeException;

class FileWriter
{
    protected $commited = false;
    protected $file;
    protected $tmp;
    protected $fp;

    public function __construct($file, $tmp)
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

        fflush($this->fp);
        fclose($this->fp);
        if (!rename($this->tmp, $this->file)) {
            throw new RuntimeException("Cannot move the temporary file");
        }
        $this->commited = true;
        return $this->file;
    }

    public function getStream()
    {
        $this->checkIsUncommitted();
        return $this->fp;
    }

    public function write($content)
    {
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
