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
 * Block Writer
 *
 * This class writes files atomically without any file locking mechanism.
 *
 * It creates an unique temporary file and upon `commit()` it will rename the temporary file to the
 * name we need. By doing so we never have partial writes, and always the latest `commit()` wins.
 *
 * @class BlockWriter
 * @author César Rodas.
 */
class BlockWriter
{
    /**
     * Is this this chunk finished?
     */
    protected $readOnly = false;
    protected $file;
    protected $tmp;
    protected $fp;

    public function __construct($file, $tmp = NULL)
    {
        $this->file = $file;
        $this->tmp  = tempnam($tmp ?: sys_get_temp_dir(), 'tmp');
        $this->fp   = fopen($this->tmp, 'w');
    }

    /**
     * Closes the temporary file, delete it and flags the object as read only.
     */
    public function rollback()
    {
        if ($this->readOnly) {
            return false;
        }
        $this->readOnly = true;

        fclose($this->fp);
        unlink($this->tmp);
    }

    /**
     * Finishes the writing of the file and saves it. After this function the object
     * is pretty much read only.
     */
    public function commit()
    {
        if ($this->readOnly) {
            return false;
        }

        $this->readOnly = true;

        fflush($this->fp);
        fclose($this->fp);
        if (!@rename($this->tmp, $this->file)) {
            throw new RuntimeException("Cannot move the temporary file");
        }
        return $this->file;
    }

    /**
     * Checks whether the argument is a valid PHP stream resource, otherwise it would be treated
     * as a stream of bytes.
     * 
     * @param mixed $input
     *
     * @return bool
     */
    protected function isStream($input)
    {
        if (!is_resource($input)) {
            return false;
        }

        return is_array(stream_get_meta_data($input));
    }

    /**
     * Exposes the temporary file's stream resource so it can be used efficiently from a higher
     * layer. If the object is flagged as read-only it will throw an exception.
     *
     * @return stream
     */
    function getStream()
    {
        $this->isWritable();
        return $this->fp;
    }

    /**
     * Exposes the filename.
     */
    public function getFileName()
    {
        return realpath($this->file) ?: $this->file;
    }

    /**
     * Writes $content into the file. $content can be an stream of bytes or a file stream. If $limit is 
     * given, it will limit the amounts of bytes to copy to its value. This function returns the amount
     * of bytes that were wrote. 
     *
     * @param mixed $content
     * @param int   $limit
     *
     * @return int
     */
    public function write($content, $limit = -1)
    {
        $this->isWritable();
        if ($this->isStream($content)) {
            $wrote = stream_copy_to_stream($content, $this->fp, $limit);
        } else {
            $wrote = fwrite($this->fp, $content, $limit === -1 ? strlen($content) : $limit);
        }
        return $wrote;
    }

    /**
     * Checks if the object is writable. If the object is flagged as readOnly it will throw an exception.
     */
    public function isWritable()
    {
        if ($this->readOnly) {
            throw new RuntimeException("Cannot perform any write modifications because the " . __CLASS__ . " object is committed");
        }
    }

    /**
     * The object was destroyed, try to rollback. If it was commited already this call to
     * rollback has no effect.
     */
    public function __destruct()
    {
        $this->rollback();
    }
}
