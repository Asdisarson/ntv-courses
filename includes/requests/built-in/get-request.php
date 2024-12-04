<?php
function builtInGetRequest($url) {
    $response = file_get_contents($url);
    logRequest('GET', $url, null, $response);
    return $response;
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

