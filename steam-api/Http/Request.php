<?php

namespace Steam\Http;

use Unirest\Request as Unirest;
use Steam\Http\Response;

class Request
{
    private $baseApiUrl = 'https://api.steampowered.com';
    private $api;

    public function __construct($api = null)
    {
      $this->api = $api;
    }

    public function call($type, $api, $method, $version, $parameters = [], $headers = [])
    {
      $url = self::buildApiUrl($api, $method, $version);

      if (!array_key_exists('key', $parameters)) {
        $parameters['key'] = $this->api->getWebApiKey();
      }

      $type = mb_strtolower($type);

      return new Response(Unirest::$type($url, $headers, $parameters));
    }

    public function buildApiUrl($api, $method, $version)
    {
      return "{$this->baseApiUrl}/{$api}/{$method}/{$version}/";
    }

    public static function __callStatic($method, $parameters)
    {
      if (!method_exists(new self, $method)) {
        return call_user_func_array([new Unirest, $method], $parameters);
      }
    }
}
