<?php
namespace App\Http\Controllers;

// use App\Http\Controllers\Controller;
use App\Http\Controllers\MiddleController;
use App;
use Cache;
use Config;
use Crypt;
use DB;
use File;
use Excel;
use Hash;
use Log;
use PDF;
use Request;
use Route;
use Session;
use Storage;
use Schema;
use Validator;
use Auth;
use JWTAuth;
use Pel;
use URL;
use Mail;
use Carbon;

class UserController extends MiddleController
{

    public function getMember(){
        $id_crypt = $this->input('id_crypt', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $data = DB::table('users')
            ->select('users.id', 'users.nama', 'vmember_card_aktif.member_card_id', DB::raw("case when vmember_card_aktif.member_card_id is not null then 1 else 0 end as checked"))
            ->leftJoin('vmember_card_aktif', function($query) use ($id_crypt){
                $query->on('vmember_card_aktif.user_id', '=', 'users.id');
                $query->on('vmember_card_aktif.card_id', '=', DB::raw(Pel::decrypt($id_crypt)));
            })
            ->orderBy('nama', 'asc')->get();

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;
        return $this->api_output($res);
    }

    public function getMemberBlast(){
        $id_crypt      = $this->input('id_crypt', 'required');
        $card_blast_id = $this->input('card_blast_id');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $data = DB::table('users')
            // ->select('vmember_card_aktif.nama', 'vmember_card_aktif.gambar', 'vmember_card_aktif.inisial', 'vmember_card_aktif.inisial_color', 'vmember_card_aktif.jabatan')
            ->join('vmember_card_aktif', function($query) use ($id_crypt){
                $query->on('vmember_card_aktif.user_id', '=', 'users.id');
                $query->on('vmember_card_aktif.card_id', '=', DB::raw(Pel::decrypt($id_crypt)));
            });
            // ->leftJoin('card_blast_notification')
            // ->orderBy('nama', 'asc')->get();
        if($card_blast_id){
            $data->select('users.id', 'vmember_card_aktif.nama', 'vmember_card_aktif.gambar', 'vmember_card_aktif.inisial', 'vmember_card_aktif.inisial_color', 'vmember_card_aktif.jabatan', 'card_blast_notification.card_blast_notification_id', DB::raw("case when card_blast_notification.card_blast_notification_id is not null then 1 else 0 end as checked"))
                ->leftJoin('card_blast_notification', function($query) use($card_blast_id){
                    $query->on('vmember_card_aktif.user_id', '=', 'card_blast_notification.user_id');
                    $query->on('card_blast_notification.card_blast_id', '=', DB::raw($card_blast_id));
                });
        }else{
            $data->select('users.id', 'vmember_card_aktif.nama', 'vmember_card_aktif.gambar', 'vmember_card_aktif.inisial', 'vmember_card_aktif.inisial_color', 'vmember_card_aktif.jabatan', DB::raw('null as card_blast_notification_id'), DB::raw("1 as checked"));
        }
        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data->orderBy('nama', 'asc')->get();
        return $this->api_output($res);
    }

    public function getMemberSchedule(){
        $id_crypt      = $this->input('id_crypt', 'required');
        $card_event_id = $this->input('card_event_id');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $data = DB::table('users')
            // ->select('vmember_card_aktif.nama', 'vmember_card_aktif.gambar', 'vmember_card_aktif.inisial', 'vmember_card_aktif.inisial_color', 'vmember_card_aktif.jabatan')
            ->join('vmember_card_aktif', function($query) use ($id_crypt){
                $query->on('vmember_card_aktif.user_id', '=', 'users.id');
                $query->on('vmember_card_aktif.card_id', '=', DB::raw(Pel::decrypt($id_crypt)));
            });
            // ->leftJoin('card_blast_notification')
            // ->orderBy('nama', 'asc')->get();
        if($card_event_id){
            $data->select('users.id', 'vmember_card_aktif.nama', 'vmember_card_aktif.gambar', 'vmember_card_aktif.inisial', 'vmember_card_aktif.inisial_color', 'vmember_card_aktif.jabatan', 'card_event_notification.card_event_notification_id', DB::raw("case when card_event_notification.card_event_notification_id is not null then 1 else 0 end as checked"))
                ->leftJoin('card_blast_notification', function($query) use($card_event_id){
                    $query->on('vmember_card_aktif.user_id', '=', 'card_event_notification.user_id');
                    $query->on('card_event_notification.card_event_id', '=', DB::raw($card_event_id));
                });
        }else{
            $data->select('users.id', 'vmember_card_aktif.nama', 'vmember_card_aktif.gambar', 'vmember_card_aktif.inisial', 'vmember_card_aktif.inisial_color', 'vmember_card_aktif.jabatan', DB::raw('null as card_event_notification_id'), DB::raw("1 as checked"));
        }
        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data->orderBy('nama', 'asc')->get();
        return $this->api_output($res);
    }
}