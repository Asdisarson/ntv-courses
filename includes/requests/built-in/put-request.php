<?php
function builtInPutRequest($url, $data) {
    try {
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'PUT',
                'content' => http_build_query($data),
            ],
        ];
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === FALSE) {
            throw new Exception("Failed to PUT data to $url");
        }
        logRequest('PUT', $url, $data, $result);
        return $result;
    } catch (Exception $e) {
        error_log($e->getMessage());
        return null;
    }
}
