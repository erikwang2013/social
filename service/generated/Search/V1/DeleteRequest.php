<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Search\V1;

class DeleteRequest extends \Google\Protobuf\Internal\Message
{
    private $index;
    private $id;

    public function getIndex() { return $this->index; }
    public function setIndex($var) { \Google\Protobuf\Internal\GPBUtil::checkString($var, true); $this->index = $var; }

    public function getId() { return $this->id; }
    public function setId($var) { \Google\Protobuf\Internal\GPBUtil::checkInt64($var); $this->id = $var; }
}
