<?php
function curlGetRequest($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL GET request failed: " . curl_error($ch));
        $response = null;
    }
    curl_close($ch);
    logRequest('GET', $url, null, $response);
    return $response;
}
