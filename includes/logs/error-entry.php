<?php

declare(strict_types=1);

namespace NTVCourses\Logs;

final class ErrorEntry
{
    private readonly string $timestamp;
    private readonly string $errorId;
    private readonly array $serverInfo;

    public function __construct(
        private readonly string $message,
        private readonly string $type,
        private readonly ?string $url = null,
        private readonly ?\Throwable $exception = null,
        private readonly ?array $context = null
    ) {
        $this->timestamp = date('Y-m-d H:i:s.u');
        $this->errorId = uniqid('err_', true);
        $this->serverInfo = $_SERVER;
    }

    public function write(): void
    {
        $logEntry = sprintf(
            "[%s] Error ID: %s\n" .
            "Type: %s\n" .
            "Message: %s\n" .
            "URL: %s\n" .
            "File: %s\n" .
            "Line: %s\n" .
            "Context: %s\n" .
            "Server Info: %s\n" .
            "Stack Trace:\n%s\n" .
            "Previous Exceptions:\n%s\n\n",
            $this->timestamp,
            $this->errorId,
            $this->type,
            $this->message,
            $this->url ?? 'N/A',
            $this->exception?->getFile() ?? 'N/A',
            $this->exception?->getLine() ?? 'N/A',
            $this->formatContext(),
            $this->formatServerInfo(),
            $this->formatStackTrace(),
            $this->formatPreviousExceptions()
        );
        
        file_put_contents(
            filename: $this->getLogPath(),
            data: $logEntry,
            flags: FILE_APPEND | LOCK_EX
        );
    }

    private function formatContext(): string
    {
        return $this->context ? json_encode($this->context, JSON_PRETTY_PRINT) : 'N/A';
    }

    private function formatServerInfo(): string
    {
        $relevantInfo = array_intersect_key(
            $this->serverInfo,
            array_flip([
                'HTTP_HOST',
                'REQUEST_URI',
                'REQUEST_METHOD',
                'HTTP_USER_AGENT',
                'REMOTE_ADDR',
                'SERVER_PROTOCOL',
                'REQUEST_TIME'
            ])
        );

        return json_encode($relevantInfo, JSON_PRETTY_PRINT);
    }

    private function formatStackTrace(): string
    {
        if (!$this->exception) {
            return 'N/A';
        }

        return $this->exception->getTraceAsString();
    }

    private function formatPreviousExceptions(): string
    {
        if (!$this->exception) {
            return 'N/A';
        }

        $previous = [];
        $ex = $this->exception->getPrevious();
        while ($ex !== null) {
            $previous[] = sprintf(
                "Message: %s\nFile: %s\nLine: %d\nTrace:\n%s",
                $ex->getMessage(),
                $ex->getFile(),
                $ex->getLine(),
                $ex->getTraceAsString()
            );
            $ex = $ex->getPrevious();
        }

        return $previous ? implode("\n\n", $previous) : 'N/A';
    }

    private function getLogPath(): string
    {
        $baseDir = dirname(__DIR__, 2) . '/logs';
        $date = date('Y-m-d');
        $filename = sprintf('error_%s.log', $date);
        
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }
        
        return $baseDir . '/' . $filename;
    }
}
