<?php

declare(strict_types=1);

namespace NTVCourses\Requests\Curl;

final class GetRequest extends CurlRequest
{
    public function execute(string $url): string|null
    {
        $this->initializeCurl($url);
        
        curl_setopt_array($this->handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);
        
        $response = $this->executeRequest();
        
        if ($response !== null) {
            $this->logRequest('GET', $url, null, $response);
        }
        
        return $response;
    }
}
