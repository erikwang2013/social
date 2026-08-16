<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Social\User\V1;

/**
 */
class UserServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Social\User\V1\PingRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Social\Common\V1\Pong>
     */
    public function Ping(\Social\User\V1\PingRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/social.user.v1.UserService/Ping',
        $argument,
        ['\Social\Common\V1\Pong', 'decode'],
        $metadata, $options);
    }

}
