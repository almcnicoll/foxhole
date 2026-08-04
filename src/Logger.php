<?php

class Logger
{
    private const MAX_BYTES = 2 * 1024 * 1024; // rotate past 2MB

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        if (is_file($path) && filesize($path) > self::MAX_BYTES) {
            rename($path, $path . '.1');
        }
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf('[%s] %s: %s%s', date('Y-m-d H:i:s'), $level, $message, PHP_EOL);
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
