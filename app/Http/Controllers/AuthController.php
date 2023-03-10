<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Auth;
use Request;
use DB;
use JWTAuth;
use Illuminate\Support\Facades\Hash;
class AuthController extends Controller
{
    public function postLogin(){
        $credentials = Request::only('username', 'password');

        if (Auth::attempt($credentials)) {
            // Save Firebase Token
            $firebase_token = Request::input('firebase_token');
            $exist_token = DB::table('user_fcm')->where('token', $firebase_token)->count();
            $tokenSave['user_id']    = Auth::user()->id;
            $tokenSave['token']      = $firebase_token;
            $tokenSave['created_at'] = new \DateTime();
            if($exist_token)
                DB::table('user_fcm')->where('token', $firebase_token)->update($tokenSave);
            else
                DB::table('user_fcm')->insert($tokenSave);


            // Authentication passed...
            $res['api_status']  = 1;
            $res['api_message'] = 'Token Berhasil di Generate';
            $res['user_id']  = Auth::user()->id;
            // $token = JWTAuth::attempt($credentials);
            $token = JWTAuth::customClaims(['device' => 'api'])->fromUser(Auth::user());
            $res['api_token']   = $token;
            return response()->json($res);
        }else{
            $res['api_status']  = 0;
            $res['api_message'] = 'Username & Password tidak sesuai. Coba Lagi.';
            $res['api_token']   = null;
            return response()->json($res,401);
        }

    }

    

    public function getPassword(){
        $password = Request::input('password');
        $res['api_status']  = 1;
        $res['api_message'] = 'Token Berhasil di Generate';
        // $res['hash'] = Hash::make($password);
        echo Hash::make($password);die();
        // return response()->json($res);

    }
}