<?php

namespace Tahadudhiya\AltMonitor\errors;

use RuntimeException;

/**
 * Alt text could not be saved. The message says why, and is safe to show the person who tried.
 */
class AltTextException extends RuntimeException
{
}
