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
 * Each time the write() is called a block file is created, which is a temporary file in the O.S. Then the finalize()
 * funciton is called all those blocks are merged into the file. Because each block is a separated file there is no
 * need for locking.
 *
 * Finalize() locks the file (meaning that only one finalize() can happen per file), because it is reposible for merging
 * all the blocks into the file.
 *
 * @class ConcurrentFileWriter
 * @author César Rodas.
 */
class ConcurrentFileWriter
{
    protected $file;
    protected $tmp;
    protected $blocks;

    public function __construct($file, $tmp = null)
    {
        $this->file = $file;
        if ($tmp) {
            $this->tmp = $tmp . '/' . sha1($this->file) . '/';
        } else {
            $this->tmp = $this->file . '.part/';
        }
        $this->blocks = $this->tmp . 'blocks/';
    }

    /**
     * Creates the file. It creates a placeholder file with some metadata on it and will
     * create all the temporary files and folders.
     *
     * Writing without creating first will throw an exception. Creating twice will not throw an
     * exception but will return false. That means it is OK to call `create()` before calling 
     * `write()`. Ideally it should be called once when the writing of the file is initialized.
     *
     * @param array $metadata
     *
     * @return bool
     */
    public function create(array $metadata = array())
    {
        if (is_file($this->file)) {
            return false;
        }

        $this->createTemporaryFolder();
        file_put_contents($this->file, $this->placeholderContent($metadata), LOCK_EX);

        return true;
    }

    /**
     * Creates the needed temporary files and folders in prepartion for
     * the file writer.
     */
    protected function createTemporaryFolder()
    {
        Util::mkdir($this->blocks);
        Util::mkdir(dirname($this->file));
        file_put_contents($this->tmp . '.lock', '', LOCK_EX);
    }

    /**
     * Prepare the placeholder data that we put in the temporary files. This function will
     * return string (JSON) which is ready for writing to disk.
     *
     * @param array $metadata
     *
     * @return string
     */
    protected function placeholderContent(Array $metadata)
    {
        return json_encode([
            'finished' => false,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Writes content to this file. This writer must provide the $offset where this data is going to
     * be written. The $content maybe a stream (the output of `fopen()`) or a string. Both type of `$content`
     * can have a `$limit` to limit the number of bytes that are written.
     *
     * @param int $offset
     * @param string|stream $content
     * @param int $limit
     *
     * @return BlockWriter Return the BlockWriter object.
     */
    public function write($offset, $input, $limit = -1)
    {
        if (!is_dir($this->blocks)) {
            throw new RuntimeException("Cannot write into the file");
        }
        $block = new BlockWriter($this->blocks . $offset, $this->tmp);
        $wrote = $block->write($input, $limit);
        $block->commit();

        return $block;
    }

    /**
     * Returns all the wrote blocks of files. All the blocks are sorted by their offset.
     *
     * @return array
     */
    public function getWroteBlocks()
    {
        if (!is_dir($this->blocks)) {
            throw new RuntimeException("cannot obtain the blocks ({$this->blocks})");
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
        }, glob($this->blocks . "*")));

        uasort($files, function($a, $b) {
            return $a['offset'] - $b['offset'];
        });

        return $files;
    }

    /**
     * Returns the empty blocks in a file, if any gap is missing `finalize()`
     * will fail and will throw an exception.
     *
     * @param $blocks Provides the list of blocks, otherwise `getWroteBlocks()` will
     *    be called.
     *
     * @return array
     */
    public function getMissingBlocks(Array $blocks = array())
    {
        $missing = array();
        $blocks  = $blocks ? $blocks : $this->getWroteBlocks();
        $last    = array_shift($blocks);

        if ($last['offset'] !== 0) {
            $missing[] = array('offset' => 0, 'size' => $last['offset']);
        }

        foreach ($blocks as $block) {
            if ($block['offset'] !== $last['offset'] + $last['size']) {
                $offset = $last['offset'] + $last['size'];
                $missing[] = array('offset' => $offset, 'size' => $block['offset'] - $offset);
            }
            $last = $block;
        }

        return $missing;
    }

    /**
     * Finalizes writing a file. The finalization of a file means check that there are no gap or missing
     * block in a file, lock the file (so no other write may happen or another `finalize()`).
     *
     * In here, after locking, all the blocks are merged into a single file. When the merging is ready,
     * we rename the temporary file as the filename we expected. When that process is done, all the blocks
     * files are deleted and the file is ready.
     *
     * @return bool
     */
    public function finalize()
    {
        if (!is_file($this->tmp . '.lock')) {
            throw new RuntimeException("File is already completed");
        }

        $lock = fopen($this->tmp . '.lock', 'r+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }

        $blocks  = $this->getWroteBlocks();
        $missing = $this->getMissingBlocks($blocks);
        if (!empty($missing)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new RuntimeException("File is incomplete, cannot finalize it");
        }

        $file = new BlockWriter($this->file, $this->tmp);
        $fp = $file->getStream();
        foreach ($blocks as $block) {
            $tmp = fopen($block['file'], 'r');
            fseek($fp, $block['offset']);
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
