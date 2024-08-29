<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HideSuperadminFlag
{
    // buatkan kode untuk menyembunyikan informasi yang berisi is_superadmin dari tabel users
    public function handle(Request $request, Closure $next): Response 
}
