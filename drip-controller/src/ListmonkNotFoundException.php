<?php
/**
 * Exception thrown when a subscriber is not found in Listmonk (404)
 * This indicates the subscriber was deleted from Listmonk.
 */
class ListmonkNotFoundException extends RuntimeException
{
}