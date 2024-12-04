<?php

declare(strict_types=1);

namespace NTVCourses\Requests\Curl;

use NTVCourses\Logs\LogEntry;
use NTVCourses\Logs\ErrorEntry;

abstract class CurlRequest
{
    protected readonly \CurlHandle $handle;

    protected function initializeCurl(string $url): void
    {
        $this->handle = curl_init($url);
        curl_setopt_array($this->handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'NTV-Courses/1.0'
        ]);
    }

    protected function executeRequest(): string|null
    {
        $response = curl_exec($this->handle);
        
        if (curl_errno($this->handle)) {
            (new ErrorEntry(
                message: curl_error($this->handle),
                type: 'CURL',
                url: curl_getinfo($this->handle, CURLINFO_EFFECTIVE_URL)
            ))->write();
            
            $response = null;
        }
        
        curl_close($this->handle);
        return $response !== false ? $response : null;
    }

    protected function logRequest(string $method, string $url, ?array $data, ?string $response): void
    {
        (new LogEntry($method, $url, $data, $response))->write();
    }
}