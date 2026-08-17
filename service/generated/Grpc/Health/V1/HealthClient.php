<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Grpc\Health\V1;

class HealthClient extends \Grpc\BaseStub
{
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    public function Check(\Grpc\Health\V1\HealthCheckRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/grpc.health.v1.Health/Check',
            $argument, ['\Grpc\Health\V1\HealthCheckResponse', 'decode'], $metadata, $options);
    }
}
