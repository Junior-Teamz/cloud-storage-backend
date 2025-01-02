<?php

use App\Http\Controllers\Auth\AuthenticatedUserWebController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('app');
});

// Route::middleware('auth:web')->group(function () {
//     Route::post('logout', [AuthenticatedUserWebController::class, 'destroy'])
//         ->name('logout');
// });

Route::get('/email_page', function () {
    return view('mails.reset-password');
})->name('email_template');

require __DIR__ . '/auth.php';
