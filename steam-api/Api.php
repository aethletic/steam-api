<?php

namespace Steam;

use Steam\Http\Request;

class Api
{
  // methods list https://steamapi.xpaw.me/
  // https://steamcommunity.com/dev/registerkey
  protected $WebApiKey;
  protected $steamId;
  public $request;
  public $market;

  public function __construct($config)
  {
    $this->WebApiKey = $config['webApiKey'];
    $this->steamId = $config['steamId'];
    $this->request = new Request($this);
    $this->market = new \SteamApi\SteamApi();
  }

  public function getWebApiKey()
  {
    return $this->WebApiKey;
  }

  public function setWebApiKey($WebApiKey)
  {
    $this->WebApiKey = $WebApiKey;
  }

  public function getSteamId()
  {
    return $this->steamId;
  }

  public function setSteamId($steamId)
  {
    $this->steamId = $steamId;
  }

  /**
   * IEconService
   */

  public function getTradeOffers($parameters = [])
  {
    $response = $this->request->call('GET', 'IEconService', 'GetTradeOffers', 'v1', $parameters);
    return $response;
  }

  public function getTradeOffer($parameters = [])
  {
    $response = $this->request->call('GET', 'IEconService', 'GetTradeOffer', 'v1', $parameters);
    return $response;
  }

  public function getTradeOffersSummary($parameters = [])
  {
    $response = $this->request->call('GET', 'IEconService', 'GetTradeOffersSummary', 'v1', $parameters);
    return $response;
  }

  public function cancelTradeOffer($parameters = [])
  {
    $response = $this->request->call('POST', 'IEconService', 'CancelTradeOffer', 'v1', $parameters);
    return $response;
  }

  public function declineTradeOffer($parameters = [])
  {
    $response = $this->request->call('POST', 'IEconService', 'DeclineTradeOffer', 'v1', $parameters);
    return $response;
  }

  /**
   * ISteamUser
   */

  public function getFriendList($steamId)
  {
    $response = $this->request->call('GET', 'ISteamUser', 'GetFriendList', 'v1', [
      'steamid' => $steamId
    ]);

    return $response;
  }

  public function getPlayerBans($steamIds = [])
  {
    if (!is_array($steamIds)) $steamIds = [$steamIds];

    $response = $this->request->call('GET', 'ISteamUser', 'GetPlayerBans', 'v1', [
      'steamids' => implode(',', $steamIds)
    ]);

    return $response;
  }

  public function getPlayerInfo($steamIds = [])
  {
    if (!is_array($steamIds)) $steamIds = [$steamIds];

    $response = $this->request->call('GET', 'ISteamUser', 'GetPlayerSummaries', 'v2', [
      'steamids' => implode(',', $steamIds)
    ]);

    return $response;
  }

  public function getUserGroupList($steamId)
  {
    $response = $this->request->call('GET', 'ISteamUser', 'GetUserGroupList', 'v1', [
      'steamid' => $steamId
    ]);

    return $response;
  }

  public function resolveVanityURL($vanityurl, $urlType = 1)
  {
    $response = $this->request->call('GET', 'ISteamUser', 'ResolveVanityURL', 'v1', [
      'vanityurl' => $vanityurl,
      'url_type' => $urlType
    ]);

    return $response;
  }
}
