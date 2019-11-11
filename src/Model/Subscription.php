<?php

namespace MCS\Model;

class Subscription
{

	private $notificationType;
	private $destination;
	private $isEnabled = true;

	public function __construct(string $notificationType, Destination $destination)
	{
		$this->notificationType = $notificationType;
		$this->destination = $destination;
		$this->isEnabled = true;
	}

	public function getNotificationType()
	{
		return $this->notificationType;
	}

	public function isEnabled()
	{
		return $this->isEnabled;
	}

	public function getDestination()
	{
		return $this->destination;
	}

	public function setNotificationType($notificationType)
	{
		$this->notificationType = $notificationType;
	}

	public function setDestination(Destination $destination)
	{
		$this->destination = $destination;
	}

	public function setIsEnabled($isEnabled)
	{
		$this->isEnabled = $isEnabled;
	}
}
