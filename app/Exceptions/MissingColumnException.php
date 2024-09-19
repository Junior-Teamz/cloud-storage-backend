<?php

namespace App\Exceptions;

use Exception;

class MissingColumnException extends Exception
{
    public function __construct($message = "Required column not found in the excel file")
    {
        parent::__construct($message);
    }
}
