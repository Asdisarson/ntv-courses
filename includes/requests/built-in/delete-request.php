<?php
function builtInDeleteRequest($url) {
    try {
        $options = [
            'http' => [
                'method'  => 'DELETE',
            ],
        ];
        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if ($result === FALSE) {
            throw new Exception("Failed to DELETE data from $url");
        }
        logRequest('DELETE', $url, null, $result);
        return $result;
    } catch (Exception $e) {
        error_log($e->getMessage());
        return null;
    }
}
