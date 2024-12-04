<?php

declare(strict_types=1);

namespace NTVCourses\Requests\Exceptions;

class RequestException extends \Exception
{
    public function __construct(
        string $message,
        private readonly string $requestType,
        private readonly string $url,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequestType(): string
    {
        return $this->requestType;
    }

    public function getRequestUrl(): string
    {
        return $this->url;
    }
} 