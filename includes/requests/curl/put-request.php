<?php

declare(strict_types=1);

namespace NTVCourses\Requests\Curl;

final class PutRequest extends CurlRequest
{
    public function execute(string $url, array $data): string|null
    {
        $this->initializeCurl($url);
        
        curl_setopt_array($this->handle, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]
        ]);
        
        $response = $this->executeRequest();
        
        if ($response !== null) {
            $this->logRequest('PUT', $url, $data, $response);
        }
        
        return $response;
    }
}
