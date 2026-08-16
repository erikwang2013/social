<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Social\Admin\V1;

/**
 */
class AdminServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Social\Admin\V1\PingRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Social\Common\V1\Pong>
     */
    public function Ping(\Social\Admin\V1\PingRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/social.admin.v1.AdminService/Ping',
        $argument,
        ['\Social\Common\V1\Pong', 'decode'],
        $metadata, $options);
    }

}
