<?php
function curlDeleteRequest($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL DELETE request failed: " . curl_error($ch));
        $response = null;
    }
    curl_close($ch);
    logRequest('DELETE', $url, null, $response);
    return $response;
}