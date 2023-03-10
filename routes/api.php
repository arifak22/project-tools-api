<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
use Illuminate\Broadcasting\BroadcastController;
// Route::middleware('auth:api')->get('/user', function (Request $request) {
//     return $request->user();
// });
use App\Helpers\Pel;
use Illuminate\Support\Facades\Broadcast;

Pel::routeController('/auth','AuthController');

Route::middleware(['jwt.verify'])->group(function () {
    Pel::routeController('/card','CardController');
    Pel::routeController('/user','UserController');
    Pel::routeController('/chat','ChatController');
    Pel::routeController('/blast','BlastController');
    Pel::routeController('/schedule','ScheduleController');
});
Broadcast::routes([
    'middleware' => [
        'jwt.verify'
    ]
]);
// Broadcast::routes(['prefix'=>'api', 'middleware'=>['auth:api']]);
// Broadcast::routes(['prefix'=>'api', 'middleware'=>['jwt.verify']]);
// Route::post('broadcasting/auth', [BroadcastController::class, 'authenticate'])
//         ->middleware(BroadcastMiddleware::class);
