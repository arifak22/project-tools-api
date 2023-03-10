<?php
	
	namespace App\Helpers;
	
	use App;
	use Cache;
	use Config;
	use DB;
	use Excel;
	use File;
	use Hash;
	use Log;
	use Mail;
	use PDF;
	use Request;
	use Route;
	use Session;
	use Storage;
	use Schema;
	use Validator;
	use Auth;
	use JWTAuth;
	use Carbon;
	
	class Pel
	{

        #ROUTING
        public static function routeController($prefix, $controller, $namespace = null, $token = false)
		{
			
			$prefix = trim($prefix, '/') . '/';
			
			$namespace = ($namespace) ?: 'App\Http\Controllers';
			
			try {
				Route::get($prefix, ['uses' => $controller . '@getIndex', 'as' => $controller . 'GetIndex']);
				
				$controller_class = new \ReflectionClass($namespace . '\\' . $controller);
				$controller_methods = $controller_class->getMethods(\ReflectionMethod::IS_PUBLIC);
				$wildcards = '/{one?}/{two?}/{three?}/{four?}/{five?}';
				foreach ($controller_methods as $method) {
					if ($method->class != 'Illuminate\Routing\Controller' && $method->name != 'getIndex') {
						if (substr($method->name, 0, 3) == 'get') {
							$method_name = substr($method->name, 3);
							$slug = array_filter(preg_split('/(?=[A-Z])/', $method_name));
							$slug = strtolower(implode('-', $slug));
							$slug = ($slug == 'index') ? '' : $slug;
							if($token){
								Route::get($prefix . $slug . $wildcards, ['uses' => $controller . '@' . $method->name, 'as' => $controller . 'Get' . $method_name]);
							}else{
								Route::get($prefix . $slug . $wildcards, ['uses' => $controller . '@' . $method->name, 'as' => $controller . 'Get' . $method_name]);
							}
						} elseif (substr($method->name, 0, 4) == 'post') {
							$method_name = substr($method->name, 4);
							$slug = array_filter(preg_split('/(?=[A-Z])/', $method_name));
							if($token){
								Route::post($prefix . strtolower(implode('-', $slug)) . $wildcards, [
									'uses' => $controller . '@' . $method->name,
									'as' => $controller . 'Post' . $method_name,
								]);
							}else{
								Route::post($prefix . strtolower(implode('-', $slug)) . $wildcards, [
									'uses' => $controller . '@' . $method->name,
									'as' => $controller . 'Post' . $method_name,
								]);
							}
						}
					}
				}
			} catch (\Exception $e) {
			
			}
		}

		#ENCRYPTION
		public static function encrypt($string){
			return openssl_encrypt('Ptpel-'.$string.'--PT2',"RC4-40", env('CRYPT_KEY', NULL));
		}
		public static function decrypt($chipertext){
			$decrypt_text =  openssl_decrypt($chipertext,"RC4-40", env('CRYPT_KEY', NULL));
			return str_replace('--PT2', "", str_replace("Ptpel-", "", $decrypt_text));
		}

		#MODEL
		public static function saveActivityLog($card_id, $tipe_id, $add_title = null, $title = null){
			if($title == null)
			$title = DB::table('activity_log_tipe')->where('activity_log_tipe_id', $tipe_id)->first()->title;

			$title = $title . ' '. $add_title;
            $saveLog['activity_log_tipe_id'] = $tipe_id;
            $saveLog['title']                = $title;
            $saveLog['noted_date']           = new \DateTime();
            $saveLog['noted_by']             = JWTAuth::user()->id;
            $saveLog['card_id']              = $card_id;
            $idLog = DB::table('activity_log')->insertGetId($saveLog, 'activity_log_id');
			return $idLog;
		}

		public static function logDB($tipe = 'insert'){
			if($tipe == 'update'){
				$save['updated_date']         = new \DateTime();
				$save['updated_by']           = JWTAuth::user()->id;
			}else{
				$save['created_date']         = new \DateTime();
				$save['created_by']           = JWTAuth::user()->id;
			}
			return $save;
		}

		public static function addNotification($tipe, $modul, $id, $title, $description = null, $differentOfUser = [], $differentTipe = '', $differentTitle = ''){
			$card = DB::table($modul)->where($modul.'_id', $id)->first();

			if($modul === 'card_blast'){
				$tableMember = 'card_blast_notification';
				$pkMember    = 'card_blast_id';
				$idMember	 = $id;
			}else if($modul === 'card_chat'){
				$tableMember = 'vmember_card_aktif';
				$pkMember    = 'card_id';
				$idMember	 = $card->card_id;
			}else if($modul === 'card_event'){
				$tableMember = 'card_event_notification';
				$pkMember    = 'card_event_id';
				$idMember	 = $id;
			}

            $listToNotif = DB::table($tableMember)
                ->where('user_id', '<>', JWTAuth::user()->id)
                ->where($pkMember, $idMember)->get();
            $addNotif = array();
            foreach($listToNotif as $key => $list){
				if(in_array($list->user_id, $differentOfUser)){
					$addNotif[$key]['type']  = $differentTipe;
					$addNotif[$key]['title'] = $differentTitle;
				}else{
					$addNotif[$key]['type']  = $tipe;
					$addNotif[$key]['title'] = $title;
				}
                $addNotif[$key]['table']        = 'card';
                $addNotif[$key]['table_id']     = $card->card_id;
                $addNotif[$key]['table_sub']    = $modul;
                $addNotif[$key]['table_sub_id'] = $id;
                $addNotif[$key]['description']  = $description ?? JWTAuth::user()->nama . ' - '.$title;

                $addNotif[$key]['date']        = new \DateTime();
                $addNotif[$key]['status']      = 'SEND';
                $addNotif[$key]['user_id']     = $list->user_id;
				self::sendnotif(null, 'PEL', 'Easy PEL', $addNotif[$key]);
            }
            DB::table('notification')->insert($addNotif);
			
		}


		public static function sendnotif($id, $title, $body, $data) {
			if(!$id)
			return false;
	
			$url = 'https://fcm.googleapis.com/fcm/send';
			$notification = [
				'title' => $title,
				'body'  => $body,
				'sound' => 'default' 
			];
			$fields = array (
					'registration_ids' => $id,
					'notification'     => $notification,
					'priority'         => 'high',
					'data'             => $data
			);
			$fields = json_encode ( $fields );
		
			$headers = array (
					'Authorization: key=' . env('FIREBASE_KEY', NULL),
					'Content-Type: application/json'
			);
		
			$ch = curl_init ();
			curl_setopt ( $ch, CURLOPT_URL, $url );
			curl_setopt ( $ch, CURLOPT_POST, true );
			curl_setopt ( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt ( $ch, CURLOPT_POSTFIELDS, $fields );
		
			$result = curl_exec ( $ch );
			\Illuminate\Support\Facades\Log::info($result);
			echo $result;
			curl_close ( $ch );
		}


		#DATE
		public static function dateFull($date){
			return Carbon::createFromFormat('Y-m-d H:i:s', $date)->formatLocalized('%A, %d %B %Y - %H:%M');
		}

    }