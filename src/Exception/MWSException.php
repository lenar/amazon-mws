<?php

namespace MCS\Exception;

class MWSException extends Exception {

	private $errorCode;

	public function __construct(srting $message, string $errorCode = "") {
		$this->errorCode = $errorCode;

		parent::__construct($message);
	}

	public function getErrorCode() {
		return $this->errorCode;
	}

}
