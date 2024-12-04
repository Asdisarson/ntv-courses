<?php
function curlPutRequest($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL PUT request failed: " . curl_error($ch));
        $response = null;
    }
    curl_close($ch);
    logRequest('PUT', $url, $data, $response);
    return $response;
}
