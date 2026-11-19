<?php

declare(strict_types=1);

/** Carries an HTTP status code through the call stack to the front controller. */
class ApiException extends Exception
{
    private int $status;
    private $payload;

    public function __construct(string $message, int $status = 400, $payload = null)
    {
        parent::__construct($message);
        $this->status  = $status;
        $this->payload = $payload;
    }

    public function getStatus(): int { return $this->status; }
    public function getPayload()     { return $this->payload; }
}
