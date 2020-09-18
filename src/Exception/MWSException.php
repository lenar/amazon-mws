<?php

namespace MCS\Exception;

class MWSException extends \Exception {

	private $errorCode;

	public function __construct($message, $errorCode = "") {
		$this->errorCode = $errorCode;

		parent::__construct($message);
	}

	public function getErrorCode() {
		return $this->errorCode;
	}

}
