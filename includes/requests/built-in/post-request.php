<?php
function builtInPostRequest($url, $data) {
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result;
}
