<?php

namespace App\Service;

/**
 * Thrown when GraphHopper cannot connect the requested points, e.g. because
 * one of them has no walkable way nearby.
 */
final class NoRouteFoundException extends \RuntimeException
{
}
