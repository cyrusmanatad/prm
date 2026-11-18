<?php

namespace App\Exceptions;

use RuntimeException;

class MailBudgetExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Email is temporarily paused by the sending limit. Please try again later.');
    }
}
