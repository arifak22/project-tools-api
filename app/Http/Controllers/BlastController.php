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

class BlastController extends MiddleController
{

    public function postCreate(){
        $id_crypt      = $this->input('id_crypt','required');
        $card_blast_id = $this->input('card_blast_id');
        $title         = $this->input('title', 'required|min:2|max:100');
        $message       = $this->input('message', 'required|min:2|max:2000');
        // $post_date     = $this->input('post_date', 'required');
        $post_date     = new \DateTime();
        $experied_date = $this->input('experied_date', 'required');
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
            return ($var['card_blast_notification_id'] === null);
        }));

        $userUnchecked = array_values(array_filter($users, function ($var) {
            return (!$var['checked']);
        }));

        $uncheckedOld = array_values(array_filter($userUnchecked, function ($var) {
            return ($var['card_blast_notification_id']>0);
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
            $save['card_id']       = $card_id;
            $save['title']         = $title;
            $save['message']       = $message;
            $save['post_date']     = $post_date;
            $save['experied_date'] = $experied_date;
            $save['is_private']    = $is_private;
            $save['status']        = 'AKTIF';
            if($method == 'update'){
                DB::table('card_blast')->where('card_blast_id', $card_blast_id)->update($save);
                $idLog = Pel::saveActivityLog($card_id, 5, $title);
            }else{
                $card_blast_id = DB::table('card_blast')->insertGetId($save, 'card_blast_id');
                $idLog = Pel::saveActivityLog($card_id, 4, $title);
            }



            #ADD NEW MEMBER
            $blastNotif = array();
            foreach($checkedNew as $i => $newMember){
                $blastNotif[$i]['card_id']       = $card_id;
                $blastNotif[$i]['card_blast_id'] = $card_blast_id;
                $blastNotif[$i]['user_id']       = $newMember['id'];
                $blastNotif[$i]['status']        = 'WAIT';
            }
            DB::table('card_blast_notification')->insert($blastNotif);

            #REMOVE MEMBER
            $blastNotifUpdate['status']          = 'NONAKTIF';
            DB::table('card_blast_notification')->whereIn('card_blast_notification_id', array_column($uncheckedOld, 'card_blast_notification_id'))->delete();


            #SEND NOTIFICATION
            if($method == 'update')
                Pel::addNotification('BLAST_UPDATE', 'card_blast', $card_blast_id, 'Updated a Blast: '. $title);
            else
                Pel::addNotification('BLAST_POST', 'card_blast',$card_blast_id, 'Posted a Blast: '. $title);

            
            #ADD LAMPIRAN
            if($lampiran){
                foreach($lampiran as $k => $l){
                    if(@$l['path']){
                        $oldPath = $l['path'] . $l['name'];
                        $newPath = 'lampiran/blast/'. $l['name'];
                        // $files = Storage::files($l['path'] . $l['name']);
                        $move = File::copy(storage_path("app/" . $oldPath), storage_path("app/" . $newPath));
                        if($move){
                            $fileSave['card_blast_id'] = $card_blast_id;
                            $fileSave['file']          = $newPath;
                            $fileSave['temp']          = 'N';
                            DB::table('card_blast_file')->where('card_blast_file_id', $l['id'])->update($fileSave);
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
    
    public function getList(){
        $id_crypt = $this->input('id_crypt', 'required');
        $status   = $this->input('status');
        $target   = $this->input('target');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id = Pel::decrypt($id_crypt);
        
        $data = DB::table('card_blast')
            ->select('card_blast.card_blast_id', 'card_blast.card_id', 'title', 'message', 'post_date', 'card_blast.status', 'is_private', 'experied_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color')
            ->leftJoin('users', 'users.id', '=', 'card_blast.created_by')
            ->leftJoin('card_blast_notification', function($query){
                $query->on('card_blast_notification.card_blast_id', '=', 'card_blast.card_blast_id');
                $query->on('card_blast_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->orderBy('post_date', 'desc')
            ->orderBy('experied_date', 'desc')
            ->where('post_date', '<=', Carbon::now())
            ->where('card_blast.card_id', $card_id);
        if($status == 'AKTIF'){
            $data->where('card_blast.status', 'AKTIF')
                ->where('experied_date', '>=', Carbon::now());
        }else if($status == 'EXPERIED'){
            $data->where('card_blast.status', 'AKTIF')
                ->where('experied_date', '<=', Carbon::now());
        }else if($status == 'ARCHIVE'){
            $data->where('card_blast.status', 'ARCHIVE');
        }

        if($target == 'USER_LOGIN'){
            $data->whereNotNull('card_blast_notification.user_id');
        }else if($target == 'ALL'){
            $data->where('card_blast.is_private', 'N');
        }

        $list = array();
        foreach($data->get() as $i => $d){
            $list[$i]['card_blast_id'] = $d->card_blast_id;
            $list[$i]['crypt_card_blast_id'] = Pel::encrypt($d->card_blast_id);
            $list[$i]['card_id']       = $d->card_id;
            $list[$i]['crypt_card_id'] = Pel::encrypt($d->card_id);
            $list[$i]['title']         = $d->title;
            $list[$i]['message']       = $d->message;
            $list[$i]['post_date']     = $d->post_date;
            $list[$i]['status']        = $d->status;
            $list[$i]['is_private']    = $d->is_private;
            $list[$i]['experied_date'] = $d->experied_date;
            $list[$i]['nama']          = $d->nama;
            $list[$i]['gambar']        = $d->gambar;
            $list[$i]['inisial']       = $d->inisial;
            $list[$i]['inisial_color'] = $d->inisial_color;
            $lampiran = DB::table('card_blast_file')->where('card_blast_id', $d->card_blast_id)->where('temp', 'N')->get();
            // $list[$i]['lampiran'] = 0;

            foreach($lampiran as $j => $l){
                $list[$i]['lampiran'][$j]['card_blast_file_id'] = $l->card_blast_file_id;
                $list[$i]['lampiran'][$j]['file_name']          = $l->file_name;
                $list[$i]['lampiran'][$j]['file']               = $l->file;
                $list[$i]['lampiran'][$j]['file_format']        = $l->file_format;
            }
        }

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $list;

        return $this->api_output($res);

    }

    public function postRemove(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_blast = $this->input('id_crypt_blast', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_blast_id = Pel::decrypt($id_crypt_blast);
        DB::beginTransaction();
        try{
            $save['status']        = 'ARCHIVE';
            DB::table('card_blast')->where('card_blast_id', $card_blast_id)->update($save);
            $title = DB::table('card_blast')->where('card_blast_id', $card_blast_id)->first()->title;
            $idLog = Pel::saveActivityLog($card_id, 6, $title);

            #SEND NOTIFICATION
            Pel::addNotification('BLAST_REMOVE', 'card_blast', $card_blast_id, 'Removed a Blast: '. $title);

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

    public function getDetail(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_blast = $this->input('id_crypt_blast', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_blast_id = Pel::decrypt($id_crypt_blast);
        
        $data = DB::table('card_blast')
            ->select('card_blast.card_blast_id', 'card_blast.card_id', 'title', 'message', 'post_date', 'card_blast.status', 'is_private', 'experied_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color', 'card_blast.created_by')
            ->leftJoin('users', 'users.id', '=', 'card_blast.created_by')
            ->leftJoin('card_blast_notification', function($query){
                $query->on('card_blast_notification.card_blast_id', '=', 'card_blast.card_blast_id');
                $query->on('card_blast_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->orderBy('post_date', 'desc')
            ->orderBy('experied_date', 'desc')
            ->where('post_date', '<=', Carbon::now())
            ->where('card_blast.card_blast_id', $card_blast_id)
            ->where('card_blast.card_id', $card_id)->first();
        $lampiran = DB::table('card_blast_file')->where('card_blast_id', $data->card_blast_id)->where('temp', 'N')->get();

        $listLampiran = array();
        foreach($lampiran as $j => $l){
            $listLampiran[$j]['card_blast_file_id'] = $l->card_blast_file_id;
            $listLampiran[$j]['file_name']          = $l->file_name;
            $listLampiran[$j]['file']               = $l->file;
            $listLampiran[$j]['file_format']        = $l->file_format;
        }

        $data->lampiran = $listLampiran;
        $users = DB::table('vmember_card_aktif')
            ->select('card_blast_notification.card_blast_notification_id', DB::raw("case when card_blast_notification.card_blast_notification_id is null then 0 else 1 end as checked"), 'vmember_card_aktif.nama','vmember_card_aktif.gambar', 'vmember_card_aktif.user_id as id','vmember_card_aktif.inisial','vmember_card_aktif.inisial_color','vmember_card_aktif.jabatan')
            ->leftJoin('card_blast_notification', function($query) use($card_id, $card_blast_id){
                $query->on('card_blast_notification.card_id', '=', 'vmember_card_aktif.card_id');
                $query->on('card_blast_notification.user_id', '=', 'vmember_card_aktif.user_id');
                $query->on('card_blast_notification.card_blast_id', '=', DB::raw($card_blast_id));
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
        $id_crypt_blast = $this->input('id_crypt_blast', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_blast_id = Pel::decrypt($id_crypt_blast);

        $data = DB::table('card_blast_comment')
            ->select('card_blast_comment.card_blast_comment_id', 'card_blast_comment.card_blast_id', 'card_blast_comment.message', 'card_blast_comment.created_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color')
            ->join('users', 'users.id', '=', 'card_blast_comment.created_by')
            ->orderBy('card_blast_comment.created_date', 'desc')
            ->where('card_blast_comment.card_blast_id', $card_blast_id)->get();

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;

        return $this->api_output($res);
    }

    public function postComment(){
        $id_crypt       = $this->input('id_crypt', 'required');
        $id_crypt_blast = $this->input('id_crypt_blast', 'required');
        $message        = $this->input('message', 'required|max:1000');
        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }
        $card_id       = Pel::decrypt($id_crypt);
        $card_blast_id = Pel::decrypt($id_crypt_blast);

        DB::beginTransaction();
        try{
            $save = Pel::logDB();
            $save['card_blast_id'] = $card_blast_id;
            $save['message']       = $message;
            $id = DB::table('card_blast_comment')->insertGetId($save, 'card_blast_comment_id');

            $messageData = DB::table('card_blast_comment')
                ->select('card_blast_comment.card_blast_comment_id', 'card_blast_comment.card_blast_id as comment_sub_id', 'card_blast_comment.message', 'card_blast_comment.created_date', 'users.nama', 'users.gambar', DB::raw('left(users.nama, 1) AS inisial'), 'inisial_color')
                ->join('users', 'users.id', '=', 'card_blast_comment.created_by')
                ->where('card_blast_comment.card_blast_id', $card_blast_id)->where('card_blast_comment.card_blast_comment_id',$id)->first();
            
            $card_blast = DB::table('card_blast')->where('card_blast_id', $card_blast_id)->first();
            $idLog = Pel::saveActivityLog($card_id, 7, $card_blast->title);
            $saveLogD['activity_log_id'] = $idLog;
            $saveLogD['description']     = $message;
            DB::table('activity_log_detail')->insert($saveLogD);

            #SEND NOTIFICATION
            Pel::addNotification('BLAST_COMMENT', 'card_blast', $card_blast_id, 'Blast: '. $card_blast->title, JWTAuth::user()->nama .' - Message: '. $message);


            $messageData->card_id = $card_id;
            $messageData->modul   = 'blast';
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