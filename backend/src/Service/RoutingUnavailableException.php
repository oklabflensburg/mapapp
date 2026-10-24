<?php

namespace App\Service;

/**
 * Thrown when the GraphHopper routing service cannot be reached or does not
 * answer.
 */
final class RoutingUnavailableException extends \RuntimeException
{
}
