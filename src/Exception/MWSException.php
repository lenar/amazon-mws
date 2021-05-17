<?php

namespace MCS\Exception;

class MWSException extends \Exception 
{
    private $errorCode;

    public function __construct(string $message = "", $errorCode = '', \Throwable $previous = null)
    {
        $this->errorCode = $errorCode;

        parent::__construct($message, (int) $errorCode, $previous);
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }
}
