<?php

namespace Kjoe;

use InvalidArgumentException;
use RuntimeException;

class Kjoe
{
    public $name = null;
    protected $basePath = null;
    protected $filename = null;
    public $duplicates = true; // allow duplicates yes or no

    public function __construct($name, $directory)
    {
        if ( ! is_string($name) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $name) !== 1) {
            throw new InvalidArgumentException('Queue names must be 1–64 letters, digits, underscores or hyphens, starting with a letter or digit.');
        }
        if ( ! is_string($directory) || $directory === '' || strpos($directory, "\0") !== false || strpos($directory, '://') !== false) {
            throw new InvalidArgumentException('Provide a local storage directory.');
        }
        if ( ! is_dir($directory)) {
            if ( ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create the queue directory.');
            }
        }
        $directory = realpath($directory);
        if ($directory === false) {
            throw new RuntimeException('Cannot resolve the queue directory.');
        }

        $this->basePath = $directory . DIRECTORY_SEPARATOR;
        $this->name = $name;
        $this->filename = $name . ".csv";

        // Create the queue file if it does not exist.
        $this->createIfNotExists();
    }

    public function jobsRemaining()
    {
        return $this->_countLines();
    }

    public function hasJob()
    {
        if ($this->_countLines() > 0) {
            return true;
        }

        return false;
    }

    public function put($item)
    {
        if (is_object($item)) {
            $item = (array)$item;
        }

        if (is_string($item)) {
            $item = str_getcsv($item, ',', '"', '"');
        }
        if ( ! is_array($item)) {
            throw new InvalidArgumentException('A job must be an array, an object, or one CSV record.');
        }

        $item = $this->_array2csv($item);

        // Duplicate checking and appending share the lock inside this method.
        return $this->addLineToFile($item);
    }

    public function take()
    {
        // Check for an empty queue inside the dequeue lock, not before it.
        $item = $this->readAndRemoveFirstLine();
        if ($item === false) {
            return false;
        }

        $item = str_getcsv($item, ',', '"', '"');
        if ( ! empty($item)) {
            // PHP reads an empty CSV field as null; jobs contain strings.
            foreach ($item as $key => $value) {
                if ($value === null) {
                    $item[$key] = '';
                }
            }

            return $item;
        }

        return false;
    }

    // Removes the first CSV record without loading the complete file. Shifts the rest up in chunks.
    // Thanks to evalarezo: https://stackoverflow.com/questions/2404707/is-this-the-most-efficient-way-to-get-and-remove-first-line-in-file
    public function readAndRemoveFirstLine()
    {
        $firstlineline = null;
        $temp_handle = @fopen($this->_getFilePath(), "r+");
        if ($temp_handle === false) {
            throw new RuntimeException('Cannot open the queue.');
        }

        try {
            if ( ! @flock($temp_handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the queue.');
            }

            // Read a complete CSV record, including quoted newlines or long fields.
            $firstlineline = $this->_readCsv($temp_handle);
            if ($firstlineline === false) {
                return false;
            }
            $firstlineline = $this->_array2csv($firstlineline);
            $write_position = 0;

            while (($line = @fgets($temp_handle, 4096)) !== false) {
                $read_position = ftell($temp_handle); // Get actual position.
                if ($read_position === false) {
                    throw new RuntimeException('Cannot find the read position in the queue.');
                }
                if (fseek($temp_handle, $write_position) !== 0) { // Move to previous position.
                    throw new RuntimeException('Cannot seek while shifting the queue.');
                }
                if (@fputs($temp_handle, $line) !== strlen($line)) { // Put chunk in previous position.
                    throw new RuntimeException('Cannot shift the queue; the file may be partly changed.');
                }
                if (fseek($temp_handle, $read_position) !== 0) { // Return to actual position.
                    throw new RuntimeException('Cannot return to the read position in the queue.');
                }
                $write_position += strlen($line); // Set write position for the next chunk.
            }
            if ( ! feof($temp_handle)) {
                throw new RuntimeException('Cannot read while shifting the queue; the file may be partly changed.');
            }
            if ( ! @ftruncate($temp_handle, $write_position)) { // Drop the repeated tail.
                throw new RuntimeException('Cannot truncate the queue after removing the job.');
            }
            if ( ! @fflush($temp_handle)) { // Write any pending changes to the file.
                throw new RuntimeException('Cannot flush the queue after removing the job.');
            }
        } finally {
            fclose($temp_handle); // Also releases the lock, including on errors.
        }

        return $firstlineline;
    }

    // Append one CSV record. Normally called by put() after CSV encoding.
    public function addLineToFile($line)
    {
        // Every record ends with a newline; existing rows do not need trimming.
        if (substr($line, -1) !== "\n") {
            $line .= "\n";
        }

        $temp_handle = @fopen($this->_getFilePath(), "r+");
        if ($temp_handle === false) {
            throw new RuntimeException('Cannot open the queue.');
        }

        try {
            if ( ! @flock($temp_handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the queue.');
            }
            if ($this->duplicates == false && $this->_lineExists($line, $temp_handle) == true) {
                return false;
            }
            if (fseek($temp_handle, 0, SEEK_END) !== 0) {
                throw new RuntimeException('Cannot seek to the end of the queue.');
            }
            if (@fwrite($temp_handle, $line) !== strlen($line)) {
                throw new RuntimeException('Cannot append the job; the queue may contain a partial record.');
            }
            if ( ! @fflush($temp_handle)) {
                throw new RuntimeException('Cannot flush the appended job.');
            }
        } finally {
            fclose($temp_handle);
        }

        return true;
    }

    private function _countLines()
    {
        $linecount = 0;
        $temp_handle = @fopen($this->_getFilePath(), "r");
        if ($temp_handle === false) {
            throw new RuntimeException('Cannot open the queue.');
        }

        try {
            if ( ! @flock($temp_handle, LOCK_SH)) {
                throw new RuntimeException('Cannot lock the queue.');
            }

            // Count CSV records; a quoted field can span several physical lines.
            while ($this->_readCsv($temp_handle) !== false) {
                $linecount++;
            }
        } finally {
            fclose($temp_handle);
        }

        return $linecount;
    }

    private function _getFilePath()
    {
        return $this->basePath . $this->filename;
    }

    public function clear()
    {
        $temp_handle = @fopen($this->_getFilePath(), "r+");
        if ($temp_handle === false) {
            throw new RuntimeException('Cannot open the queue.');
        }

        try {
            if ( ! @flock($temp_handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the queue.');
            }
            // Keep the file so all processes continue to lock the same queue.
            if ( ! @ftruncate($temp_handle, 0)) {
                throw new RuntimeException('Cannot clear the queue.');
            }
            if ( ! @fflush($temp_handle)) {
                throw new RuntimeException('Cannot flush the cleared queue.');
            }
        } finally {
            fclose($temp_handle);
        }
    }

    private function exists()
    {
        return $this->_getFilePath() !== null && is_file($this->_getFilePath());
    }

    private function createIfNotExists()
    {
        if ($this->exists() == false) {
            // The c+ mode creates a missing file without truncating an existing one.
            $temp_handle = @fopen($this->_getFilePath(), "c+");
            if ($temp_handle === false) {
                throw new RuntimeException('Cannot create the queue file.');
            }
            fclose($temp_handle);
        }
    }

    private function _lineExists($line, $temp_handle)
    {
        // The caller already holds the insertion lock on this handle.
        $item = str_getcsv($line, ',', '"', '"');
        foreach ($item as $key => $value) {
            if ($value === null) {
                $item[$key] = '';
            }
        }

        while (($buffer = $this->_readCsv($temp_handle)) !== false) {
            if ($buffer === $item) {
                return true;
            }
        }

        return false;
    }

    private function _array2csv($fields, $delimiter = ",", $enclosure = '"', $escape_char = '"')
    {
        if (empty($fields)) {
            throw new InvalidArgumentException('A job must contain at least one field.');
        }
        foreach ($fields as $key => $value) {
            if ( ! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Job fields must be scalar values or null. Encode structured data explicitly.');
            }
            // PHP 7.3 requires an escape character. Use the enclosure and double quotes ourselves.
            $fields[$key] = str_replace($enclosure, $enclosure . $enclosure, (string)$value);
        }

        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new RuntimeException('Cannot create the CSV buffer.');
        }
        try {
            if (fputcsv($buffer, $fields, $delimiter, $enclosure, $escape_char) === false) {
                throw new RuntimeException('Cannot encode the job.');
            }
            if ( ! rewind($buffer)) {
                throw new RuntimeException('Cannot rewind the CSV buffer.');
            }
            // Read the whole job, including any quoted newlines. This is one job, not the queue.
            $csv = stream_get_contents($buffer);
            if ($csv === false) {
                throw new RuntimeException('Cannot read the encoded job.');
            }
        } finally {
            fclose($buffer);
        }

        return $csv;
    }

    // Read one complete CSV record from an already-locked file.
    private function _readCsv($temp_handle)
    {
        $item = @fgetcsv($temp_handle, 0, ',', '"', '"');
        if ($item === false) {
            if ( ! feof($temp_handle)) {
                throw new RuntimeException('Cannot read the queue.');
            }

            return false;
        }
        foreach ($item as $key => $value) {
            if ($value === null) {
                $item[$key] = '';
            }
        }

        return $item;
    }
}
