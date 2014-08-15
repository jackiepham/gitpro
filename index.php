<?php


// your api credentials
$api_token		= 'bf6991895e793009...';
$private_key	= '11e28afaf92a4844...';

// your request values
$request_method = 'POST';
$request		= 'https://www.example.com/myapp/api/hello';
$http_host		= parse_url ($request, PHP_URL_HOST);
$request_uri	= parse_url ($request, PHP_URL_PATH);
$post_data		= array ('name' => 'World');
$raw_post_data	= http_build_query ($post_data);

// calculate the hash
$data = $request_method . $http_host . $request_uri . $raw_post_data;
$hash = hash_hmac ('sha256', $data, $private_key);

// make the request using curl
$ch = curl_init ();
curl_setopt ($ch, CURLOPT_URL, $request);
curl_setopt ($ch, CURLOPT_USERPWD, $api_token . ':' . $hash);
curl_setopt ($ch, CURLOPT_POST, 1);
curl_setopt ($ch, CURLOPT_POSTFIELDS, $post_data);
curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
$response = curl_exec ($ch);
curl_close ($ch);

// parse the response
$res = json_decode ($response);
if ($res->success) {
	echo 'Success: ' . $res->data;
} else {
	echo 'Error: ' . $res->error;
}