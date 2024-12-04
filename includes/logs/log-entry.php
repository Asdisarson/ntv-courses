<?php

declare(strict_types=1);

namespace NTVCourses\Logs;

final class LogEntry
{
    private readonly string $timestamp;
    private readonly array $headers;
    private readonly string $requestId;

    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly ?array $data,
        private readonly ?string $response,
        private readonly string $type = 'request',
        private readonly ?array $metadata = null
    ) {
        $this->timestamp = date('Y-m-d H:i:s.u');
        $this->requestId = uniqid('req_', true);
        $this->headers = getallheaders() ?: [];
    }

    public function write(): void
    {
        $logEntry = sprintf(
            "[%s] Request ID: %s\n" .
            "Method: %s\n" .
            "URL: %s\n" .
            "Headers: %s\n" .
            "Data: %s\n" .
            "Response: %s\n" .
            "Metadata: %s\n" .
            "Memory Usage: %s\n" .
            "Execution Time: %.4f seconds\n\n",
            $this->timestamp,
            $this->requestId,
            $this->method,
            $this->url,
            json_encode($this->headers, JSON_PRETTY_PRINT),
            $this->data ? json_encode($this->data, JSON_PRETTY_PRINT) : 'null',
            $this->formatResponse(),
            $this->metadata ? json_encode($this->metadata, JSON_PRETTY_PRINT) : 'null',
            $this->getMemoryUsage(),
            $this->getExecutionTime()
        );
        
        file_put_contents(
            filename: $this->getLogPath(),
            data: $logEntry,
            flags: FILE_APPEND | LOCK_EX
        );
    }

    private function formatResponse(): string
    {
        if ($this->response === null) {
            return 'null';
        }

        try {
            $decoded = json_decode($this->response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT);
            }
        } catch (\Throwable) {
            // If response is not JSON, return as is
        }

        return $this->response;
    }

    private function getMemoryUsage(): string
    {
        $bytes = memory_get_usage(true);
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function getExecutionTime(): float
    {
        return microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
    }

    private function getLogPath(): string
    {
        $baseDir = dirname(__DIR__, 2) . '/logs';
        $date = date('Y-m-d');
        $filename = sprintf('%s_%s.log', $this->type, $date);
        
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        
        return $baseDir . '/' . $filename;
    }
}
