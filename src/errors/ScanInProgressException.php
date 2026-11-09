<?php

namespace Tahadudhiya\AltMonitor\errors;

use RuntimeException;

/**
 * A scan was asked for while another one is queued or running.
 */
class ScanInProgressException extends RuntimeException
{
}
