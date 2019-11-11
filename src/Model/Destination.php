<?php

namespace MCS\Model;

class Destination
{
	private $deliveryChannel = 'SQS';
	private $attributeList = [];

	public function __construct(string $deliveryChannel = 'SQS')
	{
		$this->deliveryChannel = $deliveryChannel;
	}

	public function getDeliveryChannel()
	{
		return $this->deliveryChannel;
	}

	public function addAttribute(string $key, string $value)
	{
		$this->attributeList[count($this->attributeList) + 1] = [
			"Key" => $key,
			"Value" => $value
		];

		return $this->attributeList;
	}

	public function getAttributeList()
	{
		return $this->attributeList;
	}
}
