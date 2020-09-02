<?php

namespace Steam;

use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;
use Unirest\Request as Unirest;
use Steam\Http\Request;
use Steam\Api;
use Steam\Util;

class Client
{
  private $username;
  private $password;
  private $storagePath;

  private $mobile = false;

  private $mobileAuth;

  private $apiKeyDomain = '';
  private $webApiKey;

  private $steamId;
  private $sessionId;

  private $requiresCaptcha = false;
  private $captchaGID;
  private $captchaText;

  private $requiresEmail = false;
  private $emailCode;

  private $requires2FA = false;
  private $twoFactorCode;

  private $loggedIn = false;
  private $authData;

  public $api; // object of Api
  public $market; // object of https://github.com/Allyans3/steam-market-api-v2
  public $request; // object of Unirest/Request
  private $canTrade;
  private $balance;

  public const BAD_RSA = 2;
  public const NEED_CAPTCHA = 3;
  public const NEED_EMAIL = 4;
  public const NEED_2FA = 5;
  public const BAD_CREDENTIALS = 6;
  public const LOGIN_SUCCESS = 7;
  public const LOGIN_FAIL = 8;

  public const CANT_TRADE = 99;
  public const CAN_TRADE = 100;
  public const GUARD_7_DAYS_BAN = 101;

  public function __construct($config)
  {
    $this->username = !empty($config['username']) ? $config['username'] : null;
    $this->password = !empty($config['password']) ? $config['password'] : null;
    $this->webApiKey = !empty($config['webApiKey']) ? $config['webApiKey'] : null;
    $this->steamId = !empty($config['steamId']) ? $config['steamId'] : null;
    $this->storagePath = !empty($config['storagePath']) ? $config['storagePath'] : __DIR__ . DIRECTORY_SEPARATOR. 'Storage';
    $this->storagePath = rtrim($this->storagePath, DIRECTORY_SEPARATOR);
    $this->market = new \SteamApi\SteamApi();
    $this->request = new Unirest;
    $this->api = new Api([
      'webApiKey' => $this->webApiKey,
      'steamId' => $this->steamId,
    ]);
    $this->initSession();
  }

  public function auth()
  {
    $rsaResponse = $this->request()->post('https://steamcommunity.com/login/getrsakey', [], ['username' => $this->username]);
    $rsaResponse = $rsaResponse->raw_body;

    $rsaJson = json_decode($rsaResponse, true);
    if ($rsaJson == null) {
        return ['code' => self::LOGIN_FAIL, 'response' => $loginJson];
    }

    if (!$rsaJson['success']) {
        return ['code' => self::BAD_RSA, 'response' => $loginJson];
    }

    $rsa = new RSA();
    $rsa->setEncryptionMode(RSA::ENCRYPTION_PKCS1);
    $key = [
        'modulus' => new BigInteger($rsaJson['publickey_mod'], 16),
        'publicExponent' => new BigInteger($rsaJson['publickey_exp'], 16)
    ];
    $rsa->loadKey($key, RSA::PUBLIC_FORMAT_RAW);
    $encryptedPassword = base64_encode($rsa->encrypt($this->password));

    $params = [
      'username' => $this->username,
      'password' => $encryptedPassword,
      'twofactorcode' => is_null($this->twoFactorCode) ? '' : $this->twoFactorCode,
      'captchagid' => $this->requiresCaptcha ? $this->captchaGID : '-1',
      'captcha_text' => $this->requiresCaptcha ? $this->captchaText : '',
      'emailsteamid' => ($this->requires2FA || $this->requiresEmail) ? (string)$this->steamId : '',
      'emailauth' => $this->requiresEmail ? $this->emailCode : '',
      'rsatimestamp' => $rsaJson['timestamp'],
      'remember_login' => 'false'
    ];

    $loginResponse = $this->request()->post('https://steamcommunity.com/login/dologin/?l=english', ['user-agent' => 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:27.0) Gecko/20100101 Firefox/27.0'], $params);
    $loginJson = json_decode($loginResponse->raw_body, true);

    if ($loginJson == null) {
        return ['code' => self::LOGIN_FAIL, 'response' => $loginJson];
    } else if (isset($loginJson['captcha_needed']) && $loginJson['captcha_needed']) {
        $this->requiresCaptcha = true;
        $this->captchaGID = $loginJson['captcha_gid'];
        return ['code' => self::NEED_CAPTCHA, 'response' => $loginJson];
    } else if (isset($loginJson['emailauth_needed']) && $loginJson['emailauth_needed']) {
        $this->requiresEmail = true;
        $this->steamId = $loginJson['emailsteamid'];
        return ['code' => self::NEED_EMAIL, 'response' => $loginJson];
    } else if (isset($loginJson['requires_twofactor']) && $loginJson['requires_twofactor'] && !$loginJson['success']) {
        $this->requires2FA = true;
        return ['code' => self::NEED_2FA, 'response' => $loginJson];
    } else if (isset($loginJson['login_complete']) && !$loginJson['login_complete']) {
        return ['code' => self::BAD_CREDENTIALS, 'response' => $loginJson];
    } else if (isset($loginJson['message']) && stripos($loginJson['message'], 'account name or password that you have entered is incorrect') !== false) {
        return ['code' => self::BAD_CREDENTIALS, 'response' => $loginJson];
    } else if ($loginJson['success']) {
        if (isset($loginJson['oauth'])) { file_put_contents($this->getAuthPath(), $loginJson['oauth']); }

        $this->initSession();
        $this->loggedIn = true;
        $this->authData = $loginJson['transfer_parameters'];
        $this->initApiKey();
        $this->api = new Api([
          'webApiKey' => $this->webApiKey,
          'steamId' => $this->steamId,
        ]);

        return ['code' => self::LOGIN_SUCCESS, 'response' => $loginJson];
    }

    return ['code' => self::LOGIN_FAIL, 'response' => $loginJson];
  }

  public function market()
  {
    return $this->market;
  }

  public function acceptOffer($offer)
  {
    $url = 'https://steamcommunity.com/tradeoffer/' . $offer['tradeofferid'] . '/accept';
    $referer = 'https://steamcommunity.com/tradeoffer/' . $offer['tradeofferid']  . '/';
    $params = [
        'sessionid' => $this->getSessionId(),
        'serverid' => '1',
        'tradeofferid' => $offer['tradeofferid'],
        'partner' => Util::toCommunityID($offer['accountid_other'])
    ];

    $response = $this->request()->post($url, ['Referer' => $referer], $params);
    $json = json_decode($response->raw_body, true);
    print_r($json);die;
    if (is_null($json)) {
        return false;
    } else {
        return isset($json['tradeid']);
    }
  }

  public function sendReport($steamId, $abuseType = 20, $abuseDescription = null, $appId = null)
  {
    $parameters = [
      'sessionid' => $this->sessionId,
      'abuseID' => $steamId,
      'eAbuseType' => $abuseDescription,
      'abuseDescription' => $abuseDescription,
      'ingameAppID' => $appId,
      // 'json' => '1',
    ];

    $response = $this->request()->post('https://steamcommunity.com/actions/ReportAbuse/?l=english', [], $parameters);

    if (stripos($response->raw_body, 'sorry') !== false || stripos($response->raw_body, 'error')) {
      return true; // report was sent
    } else {
      return false; // error
    }
  }

  public function inviteToGroup($groupId, $steamId)
  {
    $parameters = [
      'sessionID' => $this->sessionId,
      'group' => $groupId,
      'invitee' => $steamId,
      'type' => 'groupInvite',
      'json' => '1',
    ];

    $response = $this->request()->post('https://steamcommunity.com/actions/GroupInvite/?l=english', [], $parameters);

    $data = json_decode($response->raw_body, true);

    if (isset($data['results']) && $data['results'] == 'OK') {
      return ['ok' => true, 'response' => $data];
    } else {
      return ['ok' => false, 'response' => $data];
    }
  }

  public function getAuthData()
  {
    return $this->isLoggedIn() ? $this->authData : false;
  }

  public function getAuthPath()
  {
    return "{$this->storagePath}/{$this->username}-auth";
  }

  public function getCookiePath()
  {
    return "{$this->storagePath}/{$this->username}-cookie";
  }

  public function request($config = ['proxy' => false])
  {
    if ($config['proxy']) {
      $this->request->proxy('127.0.0.1', 9050, CURLPROXY_SOCKS5, true);
      $this->request->proxyAuth('username', 'password');
    }

    $this->request->defaultHeaders([
      'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/84.0.4147.105 Safari/537.36',
      'Referer' => 'https://steamcommunity.com/',
    ]);

    $this->request->cookieFile($this->getCookiePath());

    return $this->request;
  }

  private function initSession()
  {
    $response = $this->request()->get('https://steamcommunity.com/?l=english');
    $response = $response->raw_body;

    $pattern = '/g_steamID = (.*);/';
    preg_match($pattern, $response, $matches);
    if (!isset($matches[1])) {
        throw new \Steam\Exception\Client('Unexpected response from Steam #1.');
    }

    // set steamId
    $steamId = str_replace('"', '', $matches[1]);
    if ($steamId == 'false') {
        $steamId = 0;
    }
    $this->setSteamId($steamId);

    // set sessionId
    $pattern = '/g_sessionID = (.*);/';
    preg_match($pattern, $response, $matches);
    if (!isset($matches[1])) {
        throw new \Steam\Exception\Client('Unexpected response from Steam #2.');
    }
    $sessionId = str_replace('"', '', $matches[1]);
    $this->setSessionId($sessionId);
  }

  public function getSessionId()
  {
    return $this->sessionId;
  }

  public function setSessionId($sessionId)
  {
    return $this->sessionId = $sessionId;
  }

  public function getSteamId()
  {
    return $this->steamId;
  }

  public function setSteamId($steamId)
  {
    return $this->steamId = $steamId;
  }

  public function isLoggedIn($simple = true)
  {
    if ($simple) {
      if (is_null($this->getSteamId())) {
        $this->initSession();
      }

      return $this->getSteamId() !== 0;
    }

    $response = $this->request()->get('https://steamcommunity.com/market/?l=english');

    if (stripos($response->raw_body, 'Wallet Balance') !== false) {
      return true;
    } else {
      return false;
    }
  }

  public function canTrade()
  {
    $response = $this->request()->get('https://steamcommunity.com/market/?l=english');

    if (stripos($response->raw_body, 'market_warning_header') !== false) {
      if (stripos($response->raw_body, 'Steam Guard for 7 days') !== false) {
        return [
          'ok' => false,
          'code' => self::GUARD_7_DAYS_BAN,
          'message' => 'Guard 7 days ban.',
        ];
      }
      return [
        'ok' => false,
        'code' => self::CANT_TRADE,
        'message' => 'Unrecognized error.',
      ];
    } else {
      return [
        'ok' => false,
        'code' => self::CAN_TRADE,
        'message' => 'This account can trade now.',
      ];
    }
  }

  public function getBalance()
  {
    $response = $this->request()->get('https://steamcommunity.com/market/?l=english');

    $pattern = '/<span id="marketWalletBalanceAmount">(.+?)<\/span>/';
    preg_match_all($pattern, $response->raw_body, $matches);

    if (empty($matches[1]) || sizeof($matches[1]) == 0) {
      return false;
    }

    $rawBalance = trim($matches[1][0]);
    $rawBalance = str_ireplace(',', '.', $rawBalance);
    $cleanBalance = (float) filter_var($rawBalance, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

    $this->balance = [
      'raw' => $rawBalance,
      'balance' => $cleanBalance,
    ];

    return $this->balance;
  }

  /**
   * @return string
   */
  public function getApiKey()
  {
      return $this->webApiKey;
  }

  /**
   * Set this before logging in if you want an API key to be automatically registered.
   * @param string $apiKeyDomain
   */
  public function setApiKeyDomain($apiKeyDomain)
  {
      $this->apiKeyDomain = $apiKeyDomain;
  }

  private function initApiKey($recursionLevel = 1)
  {
      if (!$this->webApiKey) {
          $url = 'https://steamcommunity.com/dev/apikey?l=english';
          $response = $this->request()->get($url)->raw_body;

          if (preg_match('/<h2>Access Denied<\/h2>/', $response)) {
              $this->webApiKey = '';
          } else if (preg_match('/<p>Key: (.*)<\/p>/', $response, $matches)) {
              $this->webApiKey = $matches[1];
          } else if ($recursionLevel < 3 && !empty($this->apiKeyDomain)) {
              $registerUrl = 'https://steamcommunity.com/dev/registerkey';
              $params = [
                  'domain' => $this->apiKeyDomain,
                  'agreeToTerms' => 'agreed',
                  'sessionid' => $this->sessionId,
                  'Submit' => 'Register'
              ];

              $this->request()->get($registerUrl, ['Referer' => $url], $params)->raw_body;
              $recursionLevel++;
              $this->initApiKey($recursionLevel);
          } else {
              $this->webApiKey = '';
          }
      }
  }

  /**
   * @return string
   */
  public function getCaptchaGID()
  {
      return $this->captchaGID;
  }

  /**
   * Use this to get the captcha image.
   * @return string
   */
  public function getCaptchaLink()
  {
      return 'https://steamcommunity.com/public/captcha.php?gid=' . $this->captchaGID;
  }

  /**
   * Set this after a captcha is encountered when logging in or creating an account.
   * @param string $captchaText
   */
  public function setCaptchaText($captchaText)
  {
      $this->captchaText = $captchaText;
  }

  /**
   * Set this after email auth is required when logging in.
   * @param string $emailCode
   */
  public function setEmailCode($emailCode)
  {
      $this->emailCode = $emailCode;
  }

  /**
   * Set this after 2FA is required when logging in.
   * @param string $twoFactorCode
   */
  public function setTwoFactorCode($twoFactorCode)
  {
      $this->twoFactorCode = $twoFactorCode;
  }

  public function setUsername($username)
  {
    $this->username = $username;
  }

  public function setPassword($password)
  {
    $this->password = $password;
  }

  public function getUsername()
  {
    return $this->username ;
  }

  public function getPassword()
  {
    return $this->password ;
  }
}
