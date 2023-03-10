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
use Illuminate\Http\Request;
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
use Illuminate\Support\Str;
use BeyondCode\LaravelWebSockets\Apps\App;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Pusher\Pusher;
use App\Events\GroupChatMessage;
// use Response;
use Illuminate\Http\Response;
// use Illuminate\Support\Facades\Response as FacadeResponse;
use Event;
use Symfony\Component\HttpFoundation\StreamedResponse;

// use Illuminate\Http\Request;
use Pion\Laravel\ChunkUpload\Exceptions\UploadFailedException;
// use Storage;
use Illuminate\Http\UploadedFile;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\AbstractHandler;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class ChatController extends MiddleController
{

    public function postAuth(Request $request){
        // $app = App::findById(Request::header('x-app-id'));

        $app = App::findById($request->header('X-App-ID'));

        $broadcaster = new PusherBroadcaster(new Pusher(
            $app->key,
            $app->secret,
            $app->id,
            []
        ));

        return $broadcaster->validAuthenticationResponse($request, []);
    }

    public function getMessageGroup(){
        $card_id   = $this->input('id');
        $last_date = $this->input('last_date');

        $query = DB::table('card_chat')
            ->select('card_chat.created_by', 'card_chat.created_date', 'card_chat.file', 'card_chat.message', 'card_chat.card_chat_id', 'file_icon',
                'vusers.nama', 'vusers.gambar', 'vusers.inisial_color', 'vusers.jabatan', 'vusers.inisial')
            ->leftJoin('file_format', 'file_format.file_format', '=', 'card_chat.file_format')
            ->leftJoin('vusers', 'vusers.id', '=', 'card_chat.created_by')
            ->where('card_id', Pel::decrypt($card_id));
        if($last_date){
            $query = $query->where('created_date', '<', $last_date);
        }

        $chatList = $query->orderBy('created_date', 'desc')->limit(20)->get();
        $list     = $chatList->toArray();
        $last     = count($list) > 0 ? $list[count($list) - 1]->created_date : 'last';

        $res['api_status']  = 1;
        $res['api_message'] = 'success';
        $res['chat_list']   = array_reverse($list);
        $res['last_date']   = $last;
        return $this->api_output($res);

    }

    public function postMessageGroup(){
        $card_id     = $this->input('card_id');
        $message     = $this->input('message');
        $listMention = $this->getInbetweenStrings('@@', '@@', $message);
        DB::beginTransaction();
        try{
            $userMention = DB::table('member_card')->whereIn('member_card_id', $listMention)->pluck('user_id')->toArray();

            $save = Pel::logDB();
            $save['card_id'] = Pel::decrypt($card_id);
            $save['message'] = $message;
            $id = DB::table('card_chat')->insertGetId($save, 'card_chat_id');

            $group = DB::table('card_chat')
                ->select('card_chat.card_id','card_chat.created_by', 'card_chat.created_date', 'card_chat.file', 'card_chat.message', 'card_chat.card_chat_id', 'file_icon',
                'vusers.nama', 'vusers.gambar', 'vusers.inisial_color', 'vusers.jabatan', 'vusers.inisial', 'card.title')
                ->leftJoin('file_format', 'file_format.file_format', '=', 'card_chat.file_format')
                ->leftJoin('vusers', 'vusers.id', '=', 'card_chat.created_by')
                ->leftJoin('card', 'card.card_id', '=', 'card_chat.card_id')
                ->where('card_chat.card_chat_id', $id)->first();

            #SEND NOTIFICATION
            Pel::addNotification('CHAT_POST', 'card_chat', $id, 'Group Chat: '. $group->title, JWTAuth::user()->nama .' - Message: '. $message, $userMention, 'CHAT_POST_MENTION', JWTAuth::user()->nama .' mentioned you at: '. $group->title);
            event(new GroupChatMessage($group));

            $res['api_status']  = 1;
            $res['api_message'] = 'success';
            $res['group'] = $group;
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

    function getInbetweenStrings($start, $end, $str){
        $matches = array();
        $regex = "/$start([a-zA-Z0-9_]*)$end/";
        preg_match_all($regex, $str, $matches);
        return $matches[1];
    }

    function getBetween($string, $start = "", $end = ""){
        if (strpos($string, $start)) { // required if $start not exist in $string
            $startCharCount = strpos($string, $start) + strlen($start);
            $firstSubStr = substr($string, $startCharCount, strlen($string));
            $endCharCount = strpos($firstSubStr, $end);
            if ($endCharCount == 0) {
                $endCharCount = strlen($firstSubStr);
            }
            return substr($firstSubStr, 0, $endCharCount);
        } else {
            return '';
        }
    }
}