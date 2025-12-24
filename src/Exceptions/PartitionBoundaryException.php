<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Exceptions;

/**
 * Exception thrown when partition boundaries are invalid (overlapping, gaps, etc.).
 */
class PartitionBoundaryException extends PartitionException
{
}
