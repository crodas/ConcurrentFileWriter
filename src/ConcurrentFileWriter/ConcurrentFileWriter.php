<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace ConcurrentFileWriter;

use RuntimeException;

/**
 * Concurrent File Writer
 *
 * This class allows PHP to write file content in paralle with very little locking. In fact locking is only
 * needed at the file creation and when the write is finalizing. This allows multiple PHP process or requests
 * to write the same file at the same time without locking each other.
 *
 * How does it work?
 *
 * Each time the write() is called a chunk file is created, which is a temporary file in the O.S. Then the finalize()
 * funciton is called all those chunks are merged into the file. Because each chunk is a separated file there is no
 * need for locking.
 *
 * Finalize() locks the file (meaning that only one finalize() can happen per file), because it is reposible for merging
 * all the chunks into the file.
 *
 * @class ConcurrentFileWriter
 * @author César Rodas.
 */
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

        $this->createTemporaryFolder();
        $this->_writeFile($this->file, $this->placeholderContent($metadata));

        return true;
    }

    protected function createTemporaryFolder()
    {
        Util::mkdir($this->chunks);
        Util::mkdir(dirname($this->file));
        file_put_contents($this->tmp . '.lock', '');
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
        return new ChunkWriter($this->chunks . $offset, $this->tmp);
    }

    public function write($offset, $input, $limit = -1)
    {
        if (!is_dir($this->chunks)) {
            throw new RuntimeException("Cannot write into the file");
        }
        $chunk = $this->getChunkFile($offset);
        $wrote = $chunk->write($input, $limit);
        $chunk->commit();

        return $chunk;
    }

    public function getChunks()
    {
        if (!is_dir($this->chunks)) {
            throw new RuntimeException("cannot obtain the chunks ({$this->chunks})");
        }
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

    public function getMissingChunks($chunks = array())
    {
        $missing = array();
        $chunks  = $chunks ? $chunks : $this->getChunks();
        $last    = array_shift($chunks);

        if ($last['offset'] !== 0) {
            $missing[] = array('offset' => 0, 'size' => $last['offset']);
        }

        foreach ($chunks as $chunk) {
            if ($chunk['offset'] !== $last['offset'] + $last['size']) {
                $offset = $last['offset'] + $last['size'];
                $missing[] = array('offset' => $offset, 'size' => $chunk['offset'] - $offset);
            }
            $last = $chunk;
        }

        return $missing;
    }

    public function finalize()
    {
        $missing = $this->getMissingChunks();
        if (!empty($missing)) {
            throw new RuntimeException("File is incomplete, cannot finalize it");
        }

        $lock = fopen($this->tmp . '.lock', 'r+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }

        $chunks = $this->getChunks();
        $file = new ChunkWriter($this->file, $this->tmp);
        $fp = $file->getStream();
        foreach ($chunks as $chunk) {
            $tmp = fopen($chunk['file'], 'r');
            fseek($fp, $chunk['offset']);
            stream_copy_to_stream($tmp, $fp);
            fclose($tmp);
        }
        $file->commit();
        Util::delete($this->tmp);

        flock($lock, LOCK_UN);
        fclose($lock); // We do not release the lock on porpuse.


        return true;
    }

}
