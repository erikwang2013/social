<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Social\Infra\V1;

/**
 */
class InfraServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Social\Infra\V1\PingRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Social\Common\V1\Pong>
     */
    public function Ping(\Social\Infra\V1\PingRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/social.infra.v1.InfraService/Ping',
        $argument,
        ['\Social\Common\V1\Pong', 'decode'],
        $metadata, $options);
    }

}
