<?php

declare(strict_types=1);

namespace NTVCourses\Requests\Curl;

final class PostRequest extends CurlRequest
{
    public function execute(string $url, array $data): string|null
    {
        $this->initializeCurl($url);
        
        curl_setopt_array($this->handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]
        ]);
        
        $response = $this->executeRequest();
        
        if ($response !== null) {
            logRequest('POST', $url, $data, $response);
        }
        
        return $response;
    }
}