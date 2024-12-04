<?php
function builtInGetRequest($url) {
    try {
        $response = file_get_contents($url);
        if ($response === FALSE) {
            throw new Exception("Failed to GET data from $url");
        }
        logRequest('GET', $url, null, $response);
        return $response;
    } catch (Exception $e) {
        error_log($e->getMessage());
        return null;
    }
}

function logRequest($method, $url, $data, $response) {
    $logEntry = sprintf(
        "[%s] %s Request to %s\nData: %s\nResponse: %s\n\n",
        date('Y-m-d H:i:s'),
        $method,
        $url,
        json_encode($data),
        $response
    );
    file_put_contents('logs/request.log', $logEntry, FILE_APPEND);
}

