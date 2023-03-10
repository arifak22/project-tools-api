<?php

namespace App\Http\Middleware;

use Closure;
use JWTAuth;
use Exception;
use Tymon\JWTAuth\Http\Middleware\BaseMiddleware;
class JwtMiddleware extends BaseMiddleware
{

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            // \Illuminate\Support\Facades\Log::info("Authorizing run " .$currentAction = \Route::currentRouteAction());
        } catch (Exception $e) {
            
            $respone['api_status'] = 0;
            $respone['api_code']   = 102;
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException){
                $respone['api_message'] = 'Token is Invalid';
            }else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException){
                $refreshed = JWTAuth::refresh(JWTAuth::getToken());
                header('Authorization: Bearer ' . $refreshed);
                $user = JWTAuth::setToken($refreshed)->toUser();
                $respone['jwt_token']   = $refreshed;
            }else{
                $respone['api_message'] = 'Authorization Token not found';
                $respone['e'] = $e;
            }
            return response()->json($respone);
        }
        return $next($request);
    }
}