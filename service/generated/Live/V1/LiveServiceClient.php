<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Live\V1;

class LiveServiceClient extends \Grpc\BaseStub
{
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    public function CreateRoom(\Live\V1\CreateRoomRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.LiveService/CreateRoom',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function ListRooms(\Live\V1\ListRoomsRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.LiveService/ListRooms',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function RoomDetail(\Live\V1\IdRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.LiveService/RoomDetail',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function CloseRoom(\Live\V1\IdRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.LiveService/CloseRoom',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function MicUp(\Live\V1\IdRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.LiveService/MicUp',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function MicDown(\Live\V1\IdRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.LiveService/MicDown',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }
}
