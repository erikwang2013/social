<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Search\V1;

class SearchServiceClient extends \Grpc\BaseStub
{
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    public function Index(\Search\V1\IndexRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.search.v1.SearchService/Index',
            $argument, ['\Search\V1\IndexResponse', 'decode'], $metadata, $options);
    }

    public function Delete(\Search\V1\DeleteRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.search.v1.SearchService/Delete',
            $argument, ['\Search\V1\IndexResponse', 'decode'], $metadata, $options);
    }

    public function Search(\Search\V1\SearchRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.search.v1.SearchService/Search',
            $argument, ['\Search\V1\SearchResponse', 'decode'], $metadata, $options);
    }
}
