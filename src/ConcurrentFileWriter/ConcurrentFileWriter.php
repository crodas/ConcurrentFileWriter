<?php

namespace ConcurrentFileWriter;

class ConcurrentFileWriter
{
    protected $file;
    protected $tmp;
    protected $chunks;

    public function __construct($file)
    {
        $this->file   = $file;
        $this->tmp    = $this->file . '.part/';
        $this->chunks = $this->tmp . 'chunks/';
    }

    public function create(array $metadata = array())
    {
        if (is_file($this->file)) {
            return false;
        }

        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0777, true);
        }

        $this->_writeFile($this->file, $this->placeholderContent($metadata));

        $this->createTemporaryFolder();

        return true;
    }

    protected function createTemporaryFolder()
    {
        mkdir($this->chunks, 0777, true);
    }

    public function placeholderContent($metadata)
    {
        return json_encode([
            'finished' => false,
            'metadata' => $metadata,
        ]);
    }

    protected function _writeFile($file, $content)
    {
        file_put_contents($file, $content, LOCK_EX);
    }

    protected function getChunkFile($offset)
    {
        return new FileWriter($this->chunks . $offset, $this->tmp);
    }

    protected function isStream($input)
    {
        if (!is_resource($input)) {
            return false;
        }

        return is_array(stream_get_meta_data($input));
    }

    public function write($offset, $input)
    {
        if (!is_dir($this->chunks)) {
            throw new RuntimeException("Cannot write into the file");
        }
        $file = $this->getChunkFile($offset);
        if ($this->isStream($input)) {
            stream_copy_to_stream($input, $file->getStream());
        } else {
            $file->write($input);
        }
        return $file->commit();
    }

    public function rrmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    $file = $dir . '/' . $object;
                    if (is_dir($file)) {
                        $this->rrmdir($file);
                    } else {
                        unlink($file);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function getChunks()
    {
        $files = array_filter(array_map(function($file) {
            $basename = basename($file);
            if (!is_numeric($basename)) {
                return false;
            }
            return [
                'offset' => (int)$basename,
                'file' => $file,
                'size' => filesize($file)
            ];
        }, glob($this->chunks . "*")));

        uasort($files, function($a, $b) {
            return $a['offset'] - $b['offset'];
        });

        return $files;
    }

    public function finalize()
    {
        $chunks = $this->getChunks();
        $file = new FileWriter($this->file, $this->tmp);
        $fp = $file->getStream();
        foreach ($chunks as $chunk) {
            $tmp = fopen($chunk['file'], 'r');
            fseek($fp, $chunk['offset']);
            stream_copy_to_stream($tmp, $fp);
            fclose($tmp);
        }
        $file->commit();
        $this->rrmdir($this->tmp);
    }

}
