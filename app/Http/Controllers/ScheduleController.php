<?php
namespace App\Http\Controllers;

// use App\Http\Controllers\Controller;

use App\Events\CommentMessage;
use App\Http\Controllers\MiddleController;
// use App;
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
// use Illuminate\Http\Request;
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

class ScheduleController extends MiddleController
{

    public function postCreate(){
        $id_crypt      = $this->input('id_crypt','required');
        $card_event_id = $this->input('card_event_id');
        $title         = $this->input('title', 'required|min:2|max:100');
        $message       = $this->input('message', 'required|min:2|max:2000');
        $start_date    = $this->input('start_date', 'required');
        $end_date      = $this->input('end_date', 'required');
        $is_private    = $this->input('is_private', 'required');
        $users         = $this->input('users');
        $method        = $this->input('method');
        $lampiran      = $this->input('lampiran');

        
        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        #MEMBER NOTIFICATION
        $userChecked = array_values(array_filter($users, function ($var) {
            return ($var['checked']);
        }));

        $checkedNew = array_values(array_filter($userChecked, function ($var) {
            return ($var['card_event_notification_id'] === null);
        }));

        $userUnchecked = array_values(array_filter($users, function ($var) {
            return (!$var['checked']);
        }));

        $uncheckedOld = array_values(array_filter($userUnchecked, function ($var) {
            return ($var['card_event_notification_id']>0);
        }));

        if(count($checkedNew) + count($userChecked) === 0 ){
            $res['api_status']  = 0;
            $res['api_message'] = 'Minimal pilih satu Notifikasi User';
            return $this->api_output($res);
        }


        DB::beginTransaction();
        try{
            $card_id = Pel::decrypt($id_crypt);

            $save = Pel::logDB($method);
            $save['card_id']    = $card_id;
            $save['title']      = $title;
            $save['message']    = $message;
            $save['start_date'] = $start_date;
            $save['end_date']   = $end_date;
            $save['is_private'] = $is_private;
            $save['status']     = 'AKTIF';
            if($method == 'update'){
                DB::table('card_event')->where('card_event_id', $card_event_id)->update($save);
                $idLog = Pel::saveActivityLog($card_id, 9, $title);
            }else{
                $card_event_id = DB::table('card_event')->insertGetId($save, 'card_event_id');
                $idLog = Pel::saveActivityLog($card_id, 8, $title);
            }

            #ADD NEW MEMBER
            $scheduleNotif = array();
            foreach($checkedNew as $i => $newMember){
                $scheduleNotif[$i]['card_id']       = $card_id;
                $scheduleNotif[$i]['card_event_id'] = $card_event_id;
                $scheduleNotif[$i]['user_id']       = $newMember['id'];
                $scheduleNotif[$i]['status']        = 'WAIT';
            }
            DB::table('card_event_notification')->insert($scheduleNotif);

            #REMOVE MEMBER
            $scheduleNotifUpdate['status']          = 'NONAKTIF';
            DB::table('card_event_notification')->whereIn('card_event_notification_id', array_column($uncheckedOld, 'card_event_notification_id'))->delete();

            #SEND NOTIFICATION
            if($method == 'update')
                Pel::addNotification('SCHEDULE_UPDATE', 'card_event', $card_event_id, 'Updated a Schedule: '. $title);
            else
                Pel::addNotification('SCHEDULE_POST', 'card_event', $card_event_id, 'Posted a Schedule: '. $title);
            
            #ADD LAMPIRAN
            if($lampiran){
                foreach($lampiran as $k => $l){
                    if(@$l['path']){
                        $oldPath = $l['path'] . $l['name'];
                        $newPath = 'lampiran/schedule/'. $l['name'];
                        // $files = Storage::files($l['path'] . $l['name']);
                        $move = File::copy(storage_path("app/" . $oldPath), storage_path("app/" . $newPath));
                        if($move){
                            $fileSave['card_event_id'] = $card_event_id;
                            $fileSave['file']          = $newPath;
                            $fileSave['temp']          = 'N';
                            DB::table('card_event_file')->where('card_event_file_id', $l['id'])->update($fileSave);
                        }
                    }
                }
            }
            #BERHASIL
            DB::commit();
            $res['api_status']  = 1;
            $res['api_message'] = $method == 'update' ? 'Data Berhasil Dirubah' : 'Data Berhasil Ditambah';
        }catch (\Illuminate\Database\QueryException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 1 Error';
            $res['e']           = $e;
        }catch (PDOException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 2 Error';
            $res['e']           = $e;
        }catch(Exception $e){
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 3 Error';
            $res['e']           = $e;
        }

        return $this->api_output($res);

    }

    public function postRemove(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_event = $this->input('id_crypt_event', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_event_id = Pel::decrypt($id_crypt_event);
        DB::beginTransaction();
        try{
            $save['status']        = 'ARCHIVE';
            DB::table('card_event')->where('card_event_id', $card_event_id)->update($save);
            $title = DB::table('card_event')->where('card_event_id', $card_event_id)->first()->title;
            $idLog = Pel::saveActivityLog($card_id, 10, $title);
            
            #SEND NOTIFICATION
            Pel::addNotification('SCHEDULE_REMOVE', 'card_event', $card_event_id, 'Removed a Schedule: '. $title);

            $res['api_status']  = 1;
            $res['api_message'] = 'success';
            DB::commit();
        }catch (\Illuminate\Database\QueryException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 1 Error';
            $res['e']           = $e;
        }catch (PDOException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 2 Error';
            $res['e']           = $e;
        }catch(Exception $e){
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 3 Error';
            $res['e']           = $e;
        }
        return $this->api_output($res);
    }

    public function getList(){
        $id_crypt = $this->input('id_crypt', 'required');
        $status   = $this->input('status');
        $target   = $this->input('target');
        $start    = substr($this->input('start'), 0, 10) . ' 00:00:00';
        $end      = substr($this->input('end'), 0, 10). ' 23:59:59';

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id = Pel::decrypt($id_crypt);
        
        $data = DB::table('card_event')
            ->select('card_event.card_event_id', 'card_event.card_id', 'title', 'message', 'card_event.status', 'is_private', 'start_date', 'end_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color')
            ->leftJoin('users', 'users.id', '=', 'card_event.created_by')
            ->leftJoin('card_event_notification', function($query){
                $query->on('card_event_notification.card_event_id', '=', 'card_event.card_event_id');
                $query->on('card_event_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->orderBy('start_date', 'asc')
            ->orderBy('end_date', 'asc')
            ->where('card_event.card_id', $card_id);
        if($status == 'AKTIF'){
            $data->where('card_event.status', 'AKTIF');
        }else if($status == 'ARCHIVE'){
            $data->where('card_event.status', 'ARCHIVE');
        }

        if($target == 'USER_LOGIN'){
            $data->whereNotNull('card_event_notification.user_id');
        }else if($target == 'ALL'){
            $data->where('card_event.is_private', 'N');
        }

        $data->where(function($query) use($start, $end){
            $query->whereBetween('start_date', [$start, $end]);
            $query->orWhereBetween('end_date', [$start, $end]);
        });

        $list = array();
        $calendar = array();
        foreach($data->get() as $i => $d){
            $list[$i]['card_event_id']       = $d->card_event_id;
            $list[$i]['crypt_card_event_id'] = Pel::encrypt($d->card_event_id);
            $list[$i]['card_id']             = $d->card_id;
            $list[$i]['crypt_card_id']       = Pel::encrypt($d->card_id);
            $list[$i]['title']               = $d->title;
            $list[$i]['message']             = $d->message;
            $list[$i]['start_date']          = $d->start_date;
            $list[$i]['end_date']            = $d->end_date;
            $list[$i]['status']              = $d->status;
            $list[$i]['is_private']          = $d->is_private;
            $list[$i]['nama']                = $d->nama;
            $list[$i]['gambar']              = $d->gambar;
            $list[$i]['inisial']             = $d->inisial;
            $list[$i]['inisial_color']       = $d->inisial_color;
            $lampiran = DB::table('card_event_file')->where('card_event_id', $d->card_event_id)->where('temp', 'N')->get();
            // $list[$i]['lampiran'] = 0;

            $calendar[$i]['title'] = $d->title;
            if( substr($d->start_date,0,10) ===  substr($d->end_date,0,10)){
                $calendar[$i]['date'] = substr($d->start_date,0,10);

            }else{

                $calendar[$i]['start'] = substr($d->start_date,0,10);
                $calendar[$i]['end']   = substr($d->end_date,0,10) .' 23:59:59';
            }

            foreach($lampiran as $j => $l){
                $list[$i]['lampiran'][$j]['card_event_file_id'] = $l->card_event_file_id;
                $list[$i]['lampiran'][$j]['file_name']          = $l->file_name;
                $list[$i]['lampiran'][$j]['file']               = $l->file;
                $list[$i]['lampiran'][$j]['file_format']        = $l->file_format;
            }
        }

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $list;
        $res['calendar']    = $calendar;

        return $this->api_output($res);

    }

    public function getDetail(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_event = $this->input('id_crypt_event', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_event_id = Pel::decrypt($id_crypt_event);
        
        $data = DB::table('card_event')
            ->select('card_event.card_event_id', 'card_event.card_id', 'title', 'message', 'start_date', 'card_event.status', 'is_private', 'end_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color', 'card_event.created_by', 'card_event.created_date')
            ->leftJoin('users', 'users.id', '=', 'card_event.created_by')
            ->leftJoin('card_event_notification', function($query){
                $query->on('card_event_notification.card_event_id', '=', 'card_event.card_event_id');
                $query->on('card_event_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->orderBy('start_date', 'desc')
            ->orderBy('end_date', 'desc')
            // ->where('start_date', '<=', Carbon::now())
            ->where('card_event.card_event_id', $card_event_id)
            ->where('card_event.card_id', $card_id)->first();
        $lampiran = DB::table('card_event_file')->where('card_event_id', $data->card_event_id)->where('temp', 'N')->get();

        $listLampiran = array();
        foreach($lampiran as $j => $l){
            $listLampiran[$j]['card_event_file_id'] = $l->card_event_file_id;
            $listLampiran[$j]['file_name']          = $l->file_name;
            $listLampiran[$j]['file']               = $l->file;
            $listLampiran[$j]['file_format']        = $l->file_format;
        }

        $data->lampiran = $listLampiran;
        $users = DB::table('vmember_card_aktif')
            ->select('card_event_notification.card_event_notification_id', DB::raw("case when card_event_notification.card_event_notification_id is null then 0 else 1 end as checked"), 'vmember_card_aktif.nama','vmember_card_aktif.gambar', 'vmember_card_aktif.user_id as id','vmember_card_aktif.inisial','vmember_card_aktif.inisial_color','vmember_card_aktif.jabatan')
            ->leftJoin('card_event_notification', function($query) use($card_id, $card_event_id){
                $query->on('card_event_notification.card_id', '=', 'vmember_card_aktif.card_id');
                $query->on('card_event_notification.user_id', '=', 'vmember_card_aktif.user_id');
                $query->on('card_event_notification.card_event_id', '=', DB::raw($card_event_id));
            })
            ->where('vmember_card_aktif.card_id', $card_id)->get();
        $data->users = $users;
        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;

        return $this->api_output($res);
    }

    public function getCommentList(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_event = $this->input('id_crypt_event', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_event_id = Pel::decrypt($id_crypt_event);

        $data = DB::table('card_event_comment')
            ->select('card_event_comment.card_event_comment_id', 'card_event_comment.card_event_id', 'card_event_comment.message', 'card_event_comment.created_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color')
            ->join('users', 'users.id', '=', 'card_event_comment.created_by')
            ->orderBy('card_event_comment.created_date', 'desc')
            ->where('card_event_comment.card_event_id', $card_event_id)->get();

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;

        return $this->api_output($res);
    }


    public function postComment(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_event = $this->input('id_crypt_event', 'required');
        $message        = $this->input('message', 'required|max:1000');
        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_event_id = Pel::decrypt($id_crypt_event);

        DB::beginTransaction();
        try{
            $save = Pel::logDB();
            $save['card_event_id'] = $card_event_id;
            $save['message']       = $message;
            $id = DB::table('card_event_comment')->insertGetId($save, 'card_event_comment_id');

            $messageData = DB::table('card_event_comment')
            ->select('card_event_comment.card_event_comment_id', 'card_event_comment.card_event_id as comment_sub_id', 'card_event_comment.message', 'card_event_comment.created_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color')
            ->join('users', 'users.id', '=', 'card_event_comment.created_by')
            ->where('card_event_comment.card_event_id', $card_event_id)->where('card_event_comment.card_event_comment_id',$id)->first();
            
            $card_event = DB::table('card_event')->where('card_event_id', $card_event_id)->first();
            $idLog = Pel::saveActivityLog($card_id, 11, $card_event->title);
            $saveLogD['activity_log_id'] = $idLog;
            $saveLogD['description']     = $message;
            DB::table('activity_log_detail')->insert($saveLogD);

            #SEND NOTIFICATION
            Pel::addNotification('SCHEDULE_COMMENT', 'card_event', $card_event_id, 'Schedule: '. $card_event->title, JWTAuth::user()->nama .' - Message: '. $message);


            $messageData->card_id = $card_id;
            $messageData->modul   = 'schedule';
            event(new CommentMessage($messageData));

            DB::commit();
            $res['api_status']  = 1;
            $res['api_message'] = 'success';
        }catch (\Illuminate\Database\QueryException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 1 Error';
            $res['e']           = $e;
        }catch (PDOException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 2 Error';
            $res['e']           = $e;
        }catch(Exception $e){
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 3 Error';
            $res['e']           = $e;
        }
        return $this->api_output($res);
    }
}