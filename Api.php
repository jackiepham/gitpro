<?php
class Api extends Controller {
	public function get() {
		return 'Hello';
	}
}

class MyMHAC extends Crypt_HMAC2 {
	public function __construct($key = null, $hash = null) {
		parent::__construct($key, $hash);
	}

	public static function get_private_key_for_public_key($public_key) {
		// extract private key from database or cache store
		return 'private_key_user_id_9999';
	}

	public static function compare($computed_signature, $received_signature) {
		if($computed_signature == $received_signature) {
			return true;
		}

		return false;
	}

	public static function authorize($request) {
		// User hit the end point API with $data, $signature and $public_key
		$message = $request['data'];
		$received_signature = $request['sig'];
		$private_key = self::get_private_key_for_public_key($request['pubKey']);

		$myHMAC = new MyMHAC($private_key, 'sha1');
		$computed_signature = base64_encode($myHMAC->hash($message));

		return self::compare($computed_signature, $received_signature);
	}
}

class Utility {
	public static $random = '';

	// Generates a random hash string
	// By default of length 30 having special characters
	public static function generateHasString($length = 30, $special_chars = true) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if($special_chars) {
			$chars .= '!@#$%^&*_-()';
		}

		$hash = '';
		for($i = 0; $i < $length; $i++) {
			$hash .= substr($chars, self::generateRandomNumber(0, strlen($chars)-1), 1);
		}

		return $hash;
	}

	// generates a random number between $min and $max
	public static function generateRandomNumber($min = 0, $max = 0) {
		// generate seed. TO-DO: Look for a better seed value everytime
		$seed = mt_rand();

		// generate $random
		// special thing about random is that it is 32(md5) + 40(sha1) + 40(sha1) = 112 long
		// hence if we cut the 1st 8 characters everytime, we can get upto 14 random numbers
		// each time the length of $random decreases and when it is less than 8, new 112 long $random is generated
		if(strlen(self::$random) < 8 ) {
			self::$random = md5(uniqid(microtime() . mt_rand(), true) . $seed);
			self::$random .= sha1(self::$random);
			self::$random .= sha1(self::$random . $seed);
		}

		// take first 8 characters
		$value = substr(self::$random, 0, 8);

		// strip first 8 character, leaving remainder for next call
		self::$random = substr(self::$random, 8);

		$value = abs(hexdec($value));
		// Reduce the value to be within the min - max range. 4294967295 = 0xffffffff = max random number
		if($max != 0) $value = $min + (($max - $min + 1) * ($value / (4294967295 + 1)));
		return abs(intval($value));
	}
}

// User Public/Private Keys
$private_key = 'private_key_user_id_xxxxxx';
$public_key = 'public_key_user_id_xxxxxx';

// Data to be submitted
$message = 'This is a HMAC verification demonstration';

// Generate content verification signature
$myHMAC = new MyMHAC($private_key, 'sha1');
$sig = base64_encode($myHMAC->hash($message));

// your request values
$request		= 'https://api.trinetsecureserver.com/api/bank';
$postData		= array ('data' => $data, 'sig' => $sig, 'pubKey' => $public_key);

// make the request using curl
$ch = curl_init ();
curl_setopt ($ch, CURLOPT_URL, $request);
//curl_setopt ($ch, CURLOPT_USERPWD, $public_key . ':' . $sig);
curl_setopt ($ch, CURLOPT_POST, 1);
curl_setopt ($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
$response = curl_exec ($ch);
curl_close ($ch);

// parse the response