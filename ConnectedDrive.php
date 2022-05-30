<?php

namespace net\bluewalk\connecteddrive;

class ConnectedDrive
{
  private $auth_url = 'https://customer.bmwgroup.com/gcdm/oauth/authenticate';
  private $auth_token_url = 'https://customer.bmwgroup.com/gcdm/oauth/token';
  private $api_url = 'https://b2vapi.bmwgroup.com/api/vehicle';
  private $client_id = '31c357a0-7a1d-4590-aa99-33b97244d048';
  private $client_password = 'c0e3393d-70a2-4f6f-9d3c-8530af64d552';

  private $api2_url = 'https://cocoapi.bmwgroup.com';

  private static $VEHILCE_INFO = '/dynamic/v1/%s';
  private static $VEHICLES = '/eadrax-vcs/v1/vehicles?apptimezone=%s&appDateTime=%s&tireGuardMode=ENABLED';

  private $config = [
    'vin' => '',
    'username' => '',
    'password' => ''
  ];
  private $auth;


  public function  __construct($config = null) {
    if (!$config)
      throw new \Exception('No config file specified');

    $this->auth = (object) [
      'token' => '',
      'expires' => 0,
      'refresh_token' => '',
      'token_type' => 'Bearer',
      'id_token' => ''
    ];

    $this->_loadConfig($config);

    if (file_exists('auth.json'))
      $this->auth = json_decode(file_get_contents('auth.json'));
  }

  private function _request($url, $method = 'GET', $data = null, $extra_headers = []) {
    $ch = curl_init();

    $headers = [];

    // Set token if exists
    if ($this->auth->token && $this->auth->expires > time())
      $headers[] = 'Authorization: Bearer ' . $this->auth->token;

    //curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:4321');

    // Default CURL options
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_HEADER, true);

    // Set POST/PUT data
    if ($method == 'POST' || $method == 'PUT') {
      if (!$data)
        throw new \Exception('No data provided for POST/PUT methods');

      if ($this->auth->expires < time()) {
        $data_str = http_build_query($data);
      } else {
        $data_str = json_encode($data);

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($data_str);
      }

      curl_setopt($ch, CURLOPT_POSTFIELDS, $data_str);
    }

    // Add extra headers
    if (count($extra_headers))
      foreach ($extra_headers as $header)
        $headers[] = $header;

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute request
    $response = curl_exec($ch);

    if (!$response)
      throw new \Exception('Unable to retrieve data: ' . curl_error($ch));

    // Get response
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    curl_close($ch);

    return (object)[
      'headers' => $header,
      'body' => $body
    ];
  }

  private function _loadConfig($config) {
    $this->config = json_decode(file_get_contents($config));
  }

  private function _saveAuth() {
    file_put_contents('auth.json', json_encode($this->auth));
  }

  private function _randomCode($length = 25) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-._~';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
  }

  public function refreshToken() {
    $headers = [
      'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
      'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_password)
    ];

    $result = $this->_request($this->auth_token_url, 'POST', [
      'redirect_uri' => 'com.bmw.connected://oauth',
      'refresh_token' => $this->auth->refresh_token,
      'grant_type' => 'refresh_token',
    ], $headers);
    
    $token = json_decode($result->body);

    $this->auth->token = $token->access_token;
    $this->auth->expires = time() + $token->expires_in;
    $this->auth->refresh_token = $token->refresh_token;
    $this->auth->id_token = $token->id_token;

    $this->_saveAuth();
  }

  public function getToken() {
    $headers = [
      'Content-Type: application/x-www-form-urlencoded',
      'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 15_3_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.3 Mobile/15E148 Safari/604.1'
    ];

    $code_challenge =  $this->_randomCode(86);
    $state = $this->_randomCode(22);

    // Stage 1 - Request authorization code
    $data = [
      'client_id' => $this->client_id,
      'response_type' => 'code',
      'scope' => 'openid profile email offline_access smacc vehicle_data perseus dlm svds cesim vsapi remote_services fupo authenticate_user',
      'redirect_uri' => 'com.bmw.connected://oauth',
      'state' => $state,
      'nonce' => 'login_nonce',
      'code_challenge' => $code_challenge,
      'code_challenge_method' => 'plain',
      'username' => $this->config->username,
      'password' => $this->config->password,
      'grant_type' => 'authorization_code'
    ];

    $result = $this->_request($this->auth_url, 'POST', $data, $headers);
    $stage1 = json_decode($result->body);

    if (!preg_match('/.*authorization=(.*)/im', $stage1->redirect_to, $matches))
      throw new \Exception('Unable to get authorization token at Stage 1');

    // Stage 2 - No idea, it's required to get the code
    $authorization = $matches[1];

    $headers[] = 'Cookie: GCDMSSO=' . $authorization;

    $data = [
      'client_id' => $this->client_id,
      'response_type' => 'code',
      'scope' => 'openid profile email offline_access smacc vehicle_data perseus dlm svds cesim vsapi remote_services fupo authenticate_user',
      'redirect_uri' => 'com.bmw.connected://oauth',
      'state' => $state,
      'nonce' => 'login_nonce',
      'code_challenge'=> $code_challenge,
      'code_challenge_method' => 'plain',
      'authorization' => $authorization
    ];

    $result = $this->_request($this->auth_url, 'POST', $data, $headers);

    if (!preg_match('/.*location:.*code=(.*?)&/im', $result->headers, $matches))
      throw new \Exception('Unable to get authorization token at Stage 2');

    $code = $matches[1];

    // Stage 3 - Get token
    $headers = [
      'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
      'Authorization: Basic ' . base64_encode($this->client_id . ':' . $this->client_password)
    ];

    $result = $this->_request($this->auth_token_url, 'POST', [
      'code' => $code,
      'code_verifier' => $code_challenge,
      'redirect_uri' => 'com.bmw.connected://oauth',
      'grant_type' => 'authorization_code',
    ], $headers);
    
    $token = json_decode($result->body);

    $this->auth->token = $token->access_token;
    $this->auth->expires = time() + $token->expires_in;
    $this->auth->refresh_token = $token->refresh_token;
    $this->auth->id_token = $token->id_token;

    $this->_saveAuth();

    return true;
  }

  private function _checkAuth() {
    if (!$this->auth->token)
      return $this->getToken();

    if ($this->auth->token && time() > $this->auth->expires)
      return $this->refreshToken();
  }

  public function getInfo() {
    $this->_checkAuth();

    $result = $this->_request($this->api_url . sprintf($this::$VEHILCE_INFO, $this->config->vin));

    return json_decode($result->body);
  }

  // This is using the new api, old api's are depricated (New BMW app) 
  public function getVehicles() {
    $this->_checkAuth();

    $headers = [
      'x-user-agent: android(SP1A.210812.016.C1);bmw;2.5.2(14945)'
    ];

    $result = $this->_request($this->api2_url . sprintf($this::$VEHICLES, (new \DateTime())->getOffset(), time()), 'GET', null, $headers);

    return json_decode($result->body);
  }
}
