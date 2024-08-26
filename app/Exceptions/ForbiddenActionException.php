<?php

namespace App\Exceptions;

use Illuminate\Http\Response;

class ForbiddenActionException
{
    protected $message;

    public function __construct($message = "This action is forbidden.")
    {
        $this->message = $message;
    }

    public function render()
    {
        return response()->json([
            'errors' => $this->message
        ], Response::HTTP_FORBIDDEN); // HTTP 403
    }
}
