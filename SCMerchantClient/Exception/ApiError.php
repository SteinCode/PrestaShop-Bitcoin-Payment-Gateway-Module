<?php

declare(strict_types=1);

namespace SpectroCoin\SCMerchantClient\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ApiError extends GenericError
{
    /**
     * @param string $message
     * @param int $code
     */
    function __construct($message, $code = 0)
    {
        parent::__construct($message, $code);
    }
}