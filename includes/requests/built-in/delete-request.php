<?php

declare(strict_types=1);

namespace NTVCourses\Requests\BuiltIn;

use NTVCourses\Requests\Exceptions\RequestException;

final class DeleteRequest
{
    public function execute(string $url): string|null
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'DELETE',
                    'header' => [
                        'Accept: application/json',
                        'User-Agent: NTV-Courses/1.0'
                    ],
                    'ignore_errors' => true,
                    'timeout' => 30,
                    'protocol_version' => 1.1
                ]
            ]);
            
            $response = file_get_contents($url, false, $context);
            
            if ($response === false) {
                throw new RequestException(
                    message: "Failed to DELETE data from URL",
                    requestType: 'DELETE',
                    url: $url
                );
            }
            
            $this->logRequest(
                method: 'DELETE',
                url: $url,
                data: null,
                response: $response
            );
            
            return $response;
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    private function logRequest(
        string $method,
        string $url,
        ?array $data,
        ?string $response
    ): void {
        $logEntry = sprintf(
            "[%s] %s Request to %s\nData: %s\nResponse: %s\n\n",
            date('Y-m-d H:i:s'),
            $method,
            $url,
            $data ? json_encode($data, JSON_PRETTY_PRINT) : 'null',
            $response ?? 'null'
        );
        
        file_put_contents(
            filename: 'logs/request.log',
            data: $logEntry,
            flags: FILE_APPEND | LOCK_EX
        );
    }
}