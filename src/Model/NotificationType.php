<?php

namespace MCS\Model;

class NotificationType
{
	const AnyOfferChanged = 'AnyOfferChanged';
	const FeedProcessingFinished = 'FeedProcessingFinished';
	const FBAOutboundShipmentStatus = 'FBAOutboundShipmentStatus';
	const FeePromotion = 'FeePromotion';
	const FulfillmentOrderStatus = 'FulfillmentOrderStatus';
	const ReportProcessingFinished = 'ReportProcessingFinished';

	public static function isValid($notificationType) {
		$refl = new \ReflectionClass(__CLASS__);
		return in_array($notificationType, $refl->getConstants());
	}
}
