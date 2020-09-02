<?php

namespace Steam\Http;

class Response
{
    private $response;

    public function __construct($response)
    {
      $this->response = $response;
    }

    public function isOk()
    {
      return $this->getCode() == 200;
    }

    public function toArray()
    {
      return json_decode($this->response->raw_body, true);
    }

    public function toObject()
    {
      return $this->response->body;
    }

    public function getCode()
    {
      return $this->response->code;
    }

    public function getHeaders()
    {
      return $this->response->headers;
    }

    public function getBody()
    {
      return $this->response->body;
    }

    public function getRawBody()
    {
      return $this->response->raw_body;
    }
}
