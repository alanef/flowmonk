<?php
/**
 * Logger - Simple file-based logging with daily rotation
 */

class Logger
{
    private string $logDir;
    private string $level;

    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warn' => 2,
        'error' => 3,
    ];

    public function __construct(string $logDir = '/var/log/drip', string $level = 'info')
    {
        $this->logDir = rtrim($logDir, '/');
        $this->level = strtolower($level);

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log an info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warn(string $message, array $context = []): void
    {
        $this->log('warn', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Write a log entry
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Check if we should log this level
        if (self::LEVELS[$level] < self::LEVELS[$this->level]) {
            return;
        }

        // Format context if provided
        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_SLASHES);
            $message .= " $contextStr";
        }

        // Build log line
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $line = "[$timestamp] $levelUpper: $message\n";

        // Write to daily log file
        $logFile = $this->logDir . '/drip-' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        // Also output to stdout for Docker logging
        echo $line;
    }

    /**
     * Clean up old log files (keep last N days)
     */
    public function cleanOldLogs(int $keepDays = 30): int
    {
        $deleted = 0;
        $cutoff = time() - ($keepDays * 86400);

        $files = glob($this->logDir . '/drip-*.log');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get the current log file path
     */
    public function getCurrentLogFile(): string
    {
        return $this->logDir . '/drip-' . date('Y-m-d') . '.log';
    }
}
