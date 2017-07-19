# Concurrent File Writer

Writing files in PHP, specially if multiple process may be writing it at the time is hard. File locking is hard to do it right and it is inneficient.

This is a tiny library to write files in parallel using as less file locking as possible. This library was designed to improve [crodas/EzUpload](https://github.com/crodas/EzUpload)

## How to install?

It can be installed with composer with the following command:

```bash
composer require crodas/concurrent-file-writer
```

## How does it work?

When a file is created, a placeholder file is created with some metadata. Each `write` call creates a new chunk, which is stored in a temporary folder. When all the chunks has been writen, the `finalize` function will lock the file and will merge all the chunks into the final, and cleanup all the temporary files.

## Usage

```php
use ConcurrentFileWriter\ConcurrentFileWriter;

$x = new ConcurrentFileWriter('/tmp/file.txt');

// This will return TRUE the first time, FALSE if the file is already create and in process and an exception is the file exists and it seems to be finished.
$x->create();

// This calls can happen in parallel (with the same initialization as above).
$x->write( $offset, $content );
$x->write( $offset, $content );

// Only one process can finalize the file writing, it will block.
// Finalize will return TRUE on success FALSE if another process is already doing it.
$x->finalize();
```

## TODO

 * [ ] More PHPDocs
 * [ ] More PHPUnit to cover all corner cases
 * [ ] More PHPUnit with real parallelism (spawning a real webserver or multiple PHP process?)
