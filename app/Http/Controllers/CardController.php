<?php
namespace App\Http\Controllers;

// use App\Http\Controllers\Controller;
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
// use Request;
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
use Str;
use BeyondCode\LaravelWebSockets\Apps\App;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Pusher\Pusher;
use App\Events\GroupChatMessage;


// use Illuminate\Http\Request;
use Pion\Laravel\ChunkUpload\Exceptions\UploadFailedException;
// use Storage;
use Illuminate\Http\UploadedFile;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\AbstractHandler;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Symfony\Component\HttpFoundation\StreamedResponse;

use Illuminate\Http\Request;

class CardController extends MiddleController
{

    #UPLOADFILE
    public function postUploadFile(Request $request)
    {

        $receiver = new FileReceiver("file", $request, HandlerFactory::classFromRequest($request));

        // check if the upload is success, throw exception or return response you need
        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException();
        }

        // receive the file
        $save = $receiver->receive();

        // check if the upload has finished (in chunk mode it will send smaller files)
        if ($save->isFinished()) {
            // save the file and return any response you need, current example uses `move` function. If you are
            // not using move, you need to manually delete the file by unlink($save->getFile()->getPathname())
            return $this->saveFile($save->getFile(), $request);
        }

        // we are in chunk mode, lets send the current progress
        /** @var AbstractHandler $handler */
        $handler = $save->handler();

        return response()->json([
            "done"   => $handler->getPercentageDone(),
            'status' => true
        ]);
    }

    /**
     * Saves the file
     *
     * @param UploadedFile $file
     *
     * @return JsonResponse
     */
    protected function saveFile(UploadedFile $file, Request $request)
    {
        
        $modul  = $request->modul;
        $id     = $request->id;
        $isTemp = $request->isTemp ?? 'N';

        $extension      = $file->extension();
        $originFileName = $file->getClientOriginalName();

        $fileName = $this->createFilename($file);

        // Get file mime type
        $mime_original = $file->getMimeType();
        $mime = str_replace('/', '-', $mime_original);
        if($isTemp === 'Y')
            $filePath = "temp/" .$modul .'/';
        else
            $filePath = "lampiran/" . $id . '/' .$modul .'/';

        $finalPath = storage_path("app/" . $filePath);

        // move the file name
        $file->move($finalPath, $fileName);


        $existExtension = DB::table('file_format')->where('file_format', $extension)->count();

        if($modul === 'chat'){
            $card_id  = Pel::decrypt($id);
            $save           = Pel::logDB();
            $save['card_id']     = $card_id;
            $save['message']     = $originFileName;
            $save['file']        = $filePath . '' . $fileName;
            $save['file_format'] = $existExtension > 0 ? $extension : 'all';
            $id = DB::table('card_chat')->insertGetId($save, 'card_chat_id');
            $group = DB::table('card_chat')
                ->select('card_chat.card_id','card_chat.created_by', 'card_chat.created_date', 'card_chat.file', 'card_chat.message', 'card_chat.card_chat_id', 'file_icon',
                'vusers.nama', 'vusers.gambar', 'vusers.inisial_color', 'vusers.jabatan', 'vusers.inisial', 'card.title')
                ->leftJoin('file_format', 'file_format.file_format', '=', 'card_chat.file_format')
                ->leftJoin('vusers', 'vusers.id', '=', 'card_chat.created_by')
                ->leftJoin('card', 'card.card_id', '=', 'card_chat.card_id')
                ->where('card_chat.card_chat_id', $id)->first();
            Pel::addNotification('CHAT_POST_FILE', 'card_chat', $id, JWTAuth::user()->nama .' uploaded file at: '. $group->title, JWTAuth::user()->nama .' - Filename: '. $group->message);

            event(new GroupChatMessage($group));
        }else if($modul === 'blast'){
            $save            = Pel::logDB();
            // $save['card_blast_id'] = $id;
            $save['file_name']     = $originFileName;
            $save['file']          = $filePath . '' . $fileName;
            $save['file_format']   = $existExtension > 0 ? $extension : 'all';
            $save['temp']          = $isTemp;

            $id = DB::table('card_blast_file')->insertGetId($save, 'card_blast_file_id');
            $group = DB::table('card_blast_file')->leftJoin('file_format', 'file_format.file_format', '=', 'card_blast_file.file_format')->where('card_blast_file_id', $id)->first();

        }else if($modul === 'schedule'){
            $save            = Pel::logDB();
            // $save['card_blast_id'] = $id;
            $save['file_name']     = $originFileName;
            $save['file']          = $filePath . '' . $fileName;
            $save['file_format']   = $existExtension > 0 ? $extension : 'all';
            $save['temp']          = $isTemp;

            $id = DB::table('card_event_file')->insertGetId($save, 'card_event_file_id');
            $group = DB::table('card_event_file')->leftJoin('file_format', 'file_format.file_format', '=', 'card_event_file.file_format')->where('card_event_file_id', $id)->first();

        }


        return response()->json([
            'path'      => $filePath,
            'file_name' => $originFileName,
            'name'      => $fileName,
            'mime_type' => $mime,
            'id'        => $id,
            'file_icon' => $group->file_icon
        ]);
    }

    /**
     * Create unique filename for uploaded file
     * @param UploadedFile $file
     * @return string
     */
    protected function createFilename(UploadedFile $file)
    {
        return Str::random(10) . '_' .$file->getClientOriginalName();
    }

    /**
     * Delete uploaded file WEB ROUTE
     * @param Request request
     * @return JsonResponse
     */
    public function delete(Request $request)
    {

        $user_obj = auth()->user();

        $file = $request->filename;

        //delete timestamp from filename
        $temp_arr = explode('_', $file);
        if (isset($temp_arr[0])) unset($temp_arr[0]);
        $file = implode('_', $temp_arr);

        $dir = $request->date;

        $filePath = "public/upload/medialibrary/{$user_obj->id}/{$dir}/";
        $finalPath = storage_path("app/" . $filePath);

        if (unlink($finalPath . $file)) {
            return response()->json([
                'status' => 'ok'
            ], 200);
        } else {
            return response()->json([
                'status' => 'error'
            ], 403);
        }
    }

    function getFileDownload(){
        $id = $this->input('id');
        $modul = $this->input('modul');
        
        if($modul === 'chat'){
            $file = DB::table('card_chat')->where('card_chat_id', $id)->first()->file;
            $filePath = storage_path("app/" . $file);
            $fileName = DB::table('card_chat')->where('card_chat_id', $id)->first()->message;
        }else if($modul === 'blast'){
            $file = DB::table('card_blast_file')->where('card_blast_file_id', $id)->first()->file;
            $filePath = storage_path("app/" . $file);
            $fileName = DB::table('card_blast_file')->where('card_blast_file_id', $id)->first()->file_name;
        }

        $response = new StreamedResponse(
            function() use ($filePath, $fileName) {
                // Open output stream
                if ($file = fopen($filePath, 'rb')) {
                    while(!feof($file) and (connection_status()==0)) {
                        print(fread($file, 1024*8));
                        flush();
                    }
                    fclose($file);
                }
            },
            200,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);
        
        return $response;
    }
    #UPLOADFILE END

    public function getMyGroup(){
        $filter = $this->input('filter');
        // \DB::enableQueryLog(); // Enable query log
        $list = DB::table('vcard')
            ->join('vmember_card_aktif', 'vmember_card_aktif.card_id', '=', 'vcard.card_id')
            ->orderBy('master_card_level_id', 'asc')
            ->where('vmember_card_aktif.user_id', JWTAuth::user()->id)
            ->where('vmember_card_aktif.status', 'AKTIF'); //CEK MEMBER MASIH AKTIF
            // ->where('vcard.status', 'AKTIF')->get();
        if($filter){
            foreach($filter as $key => $f){
                $list->where(function($query) use($f, $key){
                    $query->where('master_card_level_id', '<>', $key);
                    $query->orWhere(function($query2) use($f, $key){
                        $query2->where('master_card_level_id', $key);
                        $query2->where('vcard.status', $f);
                    });
                });
            }
        }
        $list = $list->get();
        // dd(\DB::getQueryLog()); 
        $array_list = array();
        $parent     = '';
        $i          = -1;
        $j          = -1;
        foreach($list as $key => $l){
            if($parent != $l->card_parent){
                $parent = $l->card_parent;
                $j=0;
                $i++;
                $array_list[$i]['master_card_level_id']       = $l->master_card_level_id;
                $array_list[$i]['card_parent']                = $l->card_parent;
                $array_list[$i]['icon_parent']                = $l->icon_parent;
                $array_list[$i]['child'][$j]['card_id_crypt'] = Pel::encrypt($l->card_id);
                $array_list[$i]['child'][$j]['card_id']       = $l->card_id;
                $array_list[$i]['child'][$j]['title']         = $l->title;
                $array_list[$i]['child'][$j]['icon']          = $l->icon;
                $array_list[$i]['child'][$j]['description']   = $l->description;

                #GET MEMBER
                $member = DB::table('vmember_card_aktif')->where('card_id', $l->card_id)->select('nama')->get()->toArray();
                $array_list[$i]['child'][$j]['member']        = $member;
            }else{
                $array_list[$i]['child'][$j]['card_id_crypt'] = Pel::encrypt($l->card_id);
                $array_list[$i]['child'][$j]['card_id']       = $l->card_id;
                $array_list[$i]['child'][$j]['title']         = $l->title;
                $array_list[$i]['child'][$j]['icon']          = $l->icon;
                $array_list[$i]['child'][$j]['description']   = $l->description;

                #GET MEMBER
                $member = DB::table('vmember_card_aktif')->where('card_id', $l->card_id)->select('nama')->get()->toArray();
                $array_list[$i]['child'][$j]['member']        = $member;
                
            }
            $j++;
        }

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $array_list;
        return $this->api_output($res);
    }

    public function postInsert(){
        $id                   = $this->input('card_id');
        $master_card_level_id = $this->input('master_card_level_id', 'required');
        $title                = $this->input('title', 'required|max:100');
        $description          = $this->input('description','max:200');
        $method               = $this->input('method');

        // return $this->api_output($id);
        
        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        DB::beginTransaction();
        try{

            #CARD
            $save = Pel::logDB($method);
            $save['master_card_level_id'] = $master_card_level_id;
            $save['title']                = $title;
            $save['description']          = $description;
            $save['level']                = 1;
            $save['status']               = 'AKTIF';
            if($method == 'update'){
                DB::table('card')->where('card_id', $id)->update($save);
                ##ADD LOG
                $idLog = Pel::saveActivityLog($id, 3, $title);
                $res['api_message'] = 'Data Berhasil Dirubah';
            }else{
                $id = DB::table('card')->insertGetId($save, 'card_id');
                ##ADD LOG
                $idLog = Pel::saveActivityLog($id, 1, $title);

                #CARD MEMBER
                $member['card_id']         = $id;
                $member['user_id']         = JWTAuth::user()->id;
                $member['add_date']        = new \DateTime();
                $member['add_by']          = JWTAuth::user()->id;
                $member['status']          = 'AKTIF';
                $member['activity_log_id'] = $idLog;
                DB::table('member_card')->insert($member);
    
                $res['api_message'] = 'Data Berhasil Dimasukan';
            }


            #BERHASIL
            DB::commit();
            $res['api_status']  = 1;
            // Sideveloper::createLog('InsertSukses');
        }catch (\Illuminate\Database\QueryException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 1 Error';
            $res['e']           = $e;
            // Sideveloper::createLog('Exception 1 Error', 'Exception', 'alert');
        }catch (PDOException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 2 Error';
            $res['e']           = $e;
            // Sideveloper::createLog('Exception 2 Error', 'Exception', 'alert');
        }catch(Exception $e){
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 3 Error';
            $res['e']           = $e;
            // Sideveloper::createLog('Exception 3 Error', 'Exception', 'alert');
        }

        return $this->api_output($res);


    }

    public function getDetail(){
        $id_crypt = $this->input('id_crypt', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        $data = DB::table('vcard')->where('card_id', Pel::decrypt($id_crypt))->first();

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;

        return $this->api_output($res);
    }

    public function getNotification(){
        $id_crypt = $this->input('id_crypt', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        $card_id = Pel::decrypt($id_crypt);
        #CHAT LOG
        $chatEntrance = DB::table('card_chat_log')->where('user_id', JWTAuth::user()->id)->where('card_id', $card_id)->first();
        $chat['unread']     = DB::table('card_chat')->where('card_id', $card_id)->where('created_date', '>', $chatEntrance->date ?? '2020-12-12')->count();
        $chat['last']       = DB::table('card_chat')->where('card_id', $card_id)->max('created_date');


        #BLAST LOG
        $blast['total'] = DB::table('card_blast')
            ->join('card_blast_notification', function($query){
                $query->on('card_blast_notification.card_blast_id', '=', 'card_blast.card_blast_id');
                $query->on('card_blast_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->where('card_blast.card_id', $card_id)
            ->where('card_blast.status', 'AKTIF')
            ->where('card_blast.experied_date', '>=', Carbon::now())->count();
        $blast['last'] = DB::table('card_blast') ->where('card_blast.card_id', $card_id)
            ->join('card_blast_notification', function($query){
                $query->on('card_blast_notification.card_blast_id', '=', 'card_blast.card_blast_id');
                $query->on('card_blast_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->where('card_blast.status', 'AKTIF')
            ->where('card_blast.experied_date', '>=', Carbon::now())->max('post_date');
        
        #SCHEDULE LOG
        $schedule['total'] = DB::table('card_event')
            ->join('card_event_notification', function($query){
                $query->on('card_event_notification.card_event_id', '=', 'card_event.card_event_id');
                $query->on('card_event_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->where('card_event.card_id', $card_id)
            ->where('card_event.status', 'AKTIF')
            ->where('card_event.start_date', '>=', Carbon::now())->count();
        $schedule['last'] = DB::table('card_event')
            ->join('card_event_notification', function($query){
                $query->on('card_event_notification.card_event_id', '=', 'card_event.card_event_id');
                $query->on('card_event_notification.user_id', '=', DB::raw(JWTAuth::user()->id));
            })
            ->where('card_event.card_id', $card_id)
            ->where('card_event.status', 'AKTIF')
            ->where('card_event.start_date', '>=', Carbon::now())->max('created_date');

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['chat']        = $chat;
        $res['blast']       = $blast;
        $res['schedule']    = $schedule;

        return $this->api_output($res);

    }

    public function getMember(){
        $id_crypt = $this->input('id_crypt', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        $data = DB::table('vmember_card_aktif')->where('card_id', Pel::decrypt($id_crypt))->get();

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;
        return $this->api_output($res);
    }

    public function postMember(){
        $id_crypt = $this->input('id_crypt', 'required');
        $user     = $this->input('user', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        $card_id = Pel::decrypt($id_crypt);

        $userChecked = array_values(array_filter($user, function ($var) {
            return ($var['checked']);
        }));

        $checkedNew = array_values(array_filter($userChecked, function ($var) {
            return ($var['member_card_id'] === null);
        }));

        $userUnchecked = array_values(array_filter($user, function ($var) {
            return (!$var['checked']);
        }));

        $uncheckedOld = array_values(array_filter($userUnchecked, function ($var) {
            return ($var['member_card_id']>0);
        }));
        if(count($checkedNew) + count($uncheckedOld) === 0 ){
            $res['api_status']  = 0;
            $res['api_message'] = 'Tidak ada perubahan data';
            return $this->api_output($res);
        }
        DB::beginTransaction();
        try{
            ##KURANG VALIDASI JIKA TERDOUBLE

            ##ADD LOG
            $idLog = Pel::saveActivityLog($card_id, 2);

            #ADD NEW MEMBER
            $save = array();
            foreach($checkedNew as $i => $newMember){
                $save[$i]['card_id']         = $card_id;
                $save[$i]['user_id']         = $newMember['id'];
                $save[$i]['add_date']        = new \DateTime();
                $save[$i]['add_by']          = JWTAuth::user()->id;
                $save[$i]['status']          = 'AKTIF';
                $save[$i]['activity_log_id'] = $idLog;
            }
            DB::table('member_card')->insert($save);

            #REMOVE MEMBER
            $update['status']          = 'NONAKTIF';
            $update['activity_log_id'] = $idLog;
            DB::table('member_card')->whereIn('member_card_id', array_column($uncheckedOld, 'member_card_id'))->update($update);

            #LOG DETIL
            $logDetil = DB::table('vmember_card')->where('activity_log_id', $idLog)->get();
            $saveLogD = array();
            foreach($logDetil as $key => $ld){
                $saveLogD[$key]['activity_log_id'] = $idLog;
                $saveLogD[$key]['description']     = $ld->status === 'AKTIF' ? 'Ditambahkan' : 'Dihapus';
                $saveLogD[$key]['flag']            = $ld->status === 'AKTIF' ? 'primary' : 'danger';
                $saveLogD[$key]['avatar']          = $ld->gambar;
                $saveLogD[$key]['name']            = $ld->nama;
                $saveLogD[$key]['inisial']         = ucfirst(substr($ld->nama,0,1));
                $saveLogD[$key]['inisial_color']   = DB::table('users')->where('id', $ld->user_id)->first()->inisial_color;
            }
            DB::table('activity_log_detail')->insert($saveLogD);

            ##KURANG NOTIFIKASI KE MEMBER YANG DI ADD / DI REMOVE

            #BERHASIL
            DB::commit();
            $res['api_status']  = 1;
            $res['api_message'] = 'Member berhasil diperbarui';

            // Sideveloper::createLog('InsertSukses');
        }catch (\Illuminate\Database\QueryException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 1 Error';
            $res['e']           = $e;
            // Sideveloper::createLog('Exception 1 Error', 'Exception', 'alert');
        }catch (PDOException $e) {
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 2 Error';
            $res['e']           = $e;
            // Sideveloper::createLog('Exception 2 Error', 'Exception', 'alert');
        }catch(Exception $e){
            #GAGAL
            DB::rollback();
            $res['api_status']  = 0;
            $res['api_message'] = 'Exception 3 Error';
            $res['e']           = $e;
            // Sideveloper::createLog('Exception 3 Error', 'Exception', 'alert');
        }
        return $this->api_output($res);
    }

    public function getActivity(){
        $id_crypt = $this->input('id_crypt', 'required');

        #CEK VALID
        if($this->validator()){
            return  $this->validator(true);
        }

        $card_id = Pel::decrypt($id_crypt);
        $data = DB::table('activity_list')->where('card_id', $card_id)->get();


        $array_list = array();
        $parent     = '';
        $i          = -1;
        $j          = -1;
        foreach($data as $key => $d){
            if($parent != $d->activity_log_id){
                $parent = $d->activity_log_id;
                $j=0;
                $i++;
                $array_list[$i]['activity_log_id']      = $d->activity_log_id;
                $array_list[$i]['activity_log_tipe_id'] = $d->activity_log_tipe_id;
                $array_list[$i]['icon']                 = $d->icon;
                $array_list[$i]['title']                = $d->title;
                $array_list[$i]['noted_date']           = Pel::dateFull($d->noted_date);
                $array_list[$i]['noted_by']             = $d->noted_name_by;
                $array_list[$i]['card_id']              = $d->card_id;
                if($d->activity_log_detail_id){
                    $array_list[$i]['child'][$j]['description']   = $d->description;
                    $array_list[$i]['child'][$j]['gambar']        = $d->avatar;
                    $array_list[$i]['child'][$j]['flag']          = $d->flag;
                    $array_list[$i]['child'][$j]['nama']          = $d->name;
                    $array_list[$i]['child'][$j]['inisial']       = $d->inisial;
                    $array_list[$i]['child'][$j]['inisial_color'] = $d->inisial_color;
                }else{
                    $array_list[$i]['child']     = null;
                }
            }else{
                if($d->activity_log_detail_id){
                    $array_list[$i]['child'][$j]['description']   = $d->description;
                    $array_list[$i]['child'][$j]['gambar']        = $d->avatar;
                    $array_list[$i]['child'][$j]['flag']          = $d->flag;
                    $array_list[$i]['child'][$j]['nama']          = $d->name;
                    $array_list[$i]['child'][$j]['inisial']       = $d->inisial;
                    $array_list[$i]['child'][$j]['inisial_color'] = $d->inisial_color;
                }else{
                    $array_list[$i]['child']     = null;
                }
            }
            $j++;
        }
        
        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $array_list;
        return $this->api_output($res);
    }

    public function getAllNotification(){
        $listNotif = DB::table('notification')
            ->where('notification.user_id', JWTAuth::user()->id)
            ->where('notification.status', 'SEND')->orderBy('notification.date', 'desc')->get();

        $data = array();
        foreach($listNotif as $i => $list){
            $data[$i]                 = (array) $list;
            $data[$i]['menu']         = $list->table;
            $data[$i]['crypt_id']     = Pel::encrypt($list->table_id);
            $data[$i]['menu_sub']     = str_replace("card_","",$list->table_sub);
            $data[$i]['crypt_sub_id'] = Pel::encrypt($list->table_sub_id);
        }

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['data']        = $data;
        return $this->api_output($res);
    }

    public function postNotificationRead(){
        $notification_id = $this->input('notification_id', 'required');

        $save['status'] = 'READ';
        DB::table('notification')
            ->where('notification_id', $notification_id)
            ->where('user_id', JWTAuth::user()->id)
            ->update($save);

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        return $this->api_output($res);
    }
    public function postVerifyToken(){
        $token = $this->input('api_token');
        $user  = JWTAuth::user();
        return response()->json($user);

    }

}