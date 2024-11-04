<?php

namespace App\Exceptions;

use Exception;

/**
 * This is Custom Exception for Exception if required column in import excel 
 * not found (example: Importing Tags and Instances).
 */
class MissingColumnException extends Exception
{
    public function __construct($message = "Required column not found in the excel file")
    {
        parent::__construct($message);
    }
}
