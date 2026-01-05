<?php
/**
 * Subscriber Not Found Exception
 *
 * FA-18: Thrown when a subscriber has been deleted from Listmonk.
 * This allows the drip processor to distinguish between "subscriber gone"
 * and actual API errors.
 */

class SubscriberNotFoundException extends RuntimeException
{
    private int $subscriberId;

    public function __construct(int $subscriberId, string $message = '')
    {
        $this->subscriberId = $subscriberId;
        parent::__construct($message ?: "Subscriber $subscriberId not found in Listmonk (deleted or does not exist)");
    }

    public function getSubscriberId(): int
    {
        return $this->subscriberId;
    }
}
