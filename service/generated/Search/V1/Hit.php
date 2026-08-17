<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Search\V1;

class Hit extends \Google\Protobuf\Internal\Message
{
    private $id;
    private $json;

    public function getId() { return $this->id; }
    public function setId($var) { \Google\Protobuf\Internal\GPBUtil::checkInt64($var); $this->id = $var; }

    public function getJson() { return $this->json; }
    public function setJson($var) { \Google\Protobuf\Internal\GPBUtil::checkString($var, true); $this->json = $var; }
}
