<?php
function builtInGetRequest($url) {
    $response = file_get_contents($url);
    return $response;
}

