<?php
function builtInDeleteRequest($url) {
    $options = [
        'http' => [
            'method'  => 'DELETE',
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result;
}
