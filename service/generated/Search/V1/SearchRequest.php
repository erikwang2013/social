<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Search\V1;

class SearchRequest extends \Google\Protobuf\Internal\Message
{
    private $index;
    private $query;
    private $from;
    private $size;

    public function getIndex() { return $this->index; }
    public function setIndex($var) { \Google\Protobuf\Internal\GPBUtil::checkString($var, true); $this->index = $var; }

    public function getQuery() { return $this->query; }
    public function setQuery($var) { \Google\Protobuf\Internal\GPBUtil::checkString($var, true); $this->query = $var; }

    public function getFrom() { return $this->from; }
    public function setFrom($var) { \Google\Protobuf\Internal\GPBUtil::checkInt32($var); $this->from = $var; }

    public function getSize() { return $this->size; }
    public function setSize($var) { \Google\Protobuf\Internal\GPBUtil::checkInt32($var); $this->size = $var; }
}
