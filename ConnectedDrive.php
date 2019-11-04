<?php

namespace net\bluewalk\connecteddrive;

class ConnectedDrive
{
  private $auth_url = 'https://customer.bmwgroup.com/gcdm/oauth/authenticate';
  private $api_url = 'https://www.bmw-connecteddrive.nl/api/vehicle';
  private $config = [
    'vin' => '',
    'username' => '',
    'password' => ''
  ];
  private $auth;

  private static $VEHILCE_INFO = '/dynamic/v1/%s';
  private static $REMOTESERVICES_STATUS = '/remoteservices/v1/%s/state/execution';
  private static $NAVIGATION_INFO = '/navigation/v1/%s';
  private static $EFFICIENCY = '/efficiency/v1/%s';

  public function  __construct($config = null) {
    if (!$config)
      throw new \Exception('No config file specified');

    $this->auth = (object) [
      'token' => '',
      'expires' => 0
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
        throw new Exception('No data provided for POST/PUT methods');

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
      throw new \Exception('Unable to retrieve data');

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

  public function getToken() {
    $headers = [
      'Content-Type: application/x-www-form-urlencoded',
      'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 11_1_1 like Mac OS X) AppleWebKit/604.3.5 (KHTML, like Gecko) Version/11.0 Mobile/15B150 Safari/604.1'
    ];

    $data = [
      'username' => $this->config->username,
      'password' => $this->config->password,
      'client_id' => 'dbf0a542-ebd1-4ff0-a9a7-55172fbfce35',
      'response_type' => 'token',
      'scope' => 'authenticate_user fupo',
      'state' => 'eyJtYXJrZXQiOiJubCIsImxhbmd1YWdlIjoibmwiLCJkZXN0aW5hdGlvbiI6ImxhbmRpbmdQYWdlIn0',
      'locale' => 'NL-nl',
      'redirect_uri' => 'https://www.bmw-connecteddrive.com/app/static/external-dispatch.html'
    ];

    $result = $this->_request($this->auth_url, 'POST', $data, $headers);

    if (preg_match('/.*access_token=([\w\d]+).*token_type=(\w+).*expires_in=(\d+).*/im', $result->headers, $matches)) {
      $this->auth->token = $matches[1];
      $this->auth->expires = time() + $matches[3];

      $this->_saveAuth();

      return true;
    }

    throw new \Exception('Unable to get authorization token');
  }

  private function _checkAuth() {
    if (!$this->auth->token)
      return $this->getToken();

    if ($this->auth->token && time() > $this->auth->expires)
      return $this->getToken();
  }

  public function getInfo() {
    $this->_checkAuth();

    $result = $this->_request($this->api_url . sprintf($this::$VEHILCE_INFO, $this->config->vin));

    return json_decode($result->body);
  }

  public function getRemoteServicesStatus() {
    $this->_checkAuth();

    $result = $this->_request($this->api_url . sprintf($this::$REMOTESERVICES_STATUS, $this->config->vin), 'GET', null, ['Accept: application/json']);

    return json_decode($result->body);
  }

  public function getNavigationInfo() {
    $this->_checkAuth();

    $result = $this->_request($this->api_url . sprintf($this::$NAVIGATION_INFO, $this->config->vin));

    return json_decode($result->body);
  }

  public function getEfficiency()
  {
    $this->_checkAuth();

    $result = $this->_request($this->api_url . sprintf($this::$EFFICIENCY, $this->config->vin));

    return json_decode($result->body);
  }
}
