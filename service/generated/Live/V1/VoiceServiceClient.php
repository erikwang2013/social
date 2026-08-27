<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Live\V1;

class VoiceServiceClient extends \Grpc\BaseStub
{
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    public function CreateRoom(\Live\V1\CreateVoiceRoomRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/CreateRoom',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function ListRooms(\Live\V1\ListRoomsRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/ListRooms',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function RoomDetail(\Live\V1\IdRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/RoomDetail',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function CloseRoom(\Live\V1\IdRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/CloseRoom',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function ListCalls(\Live\V1\ListRoomsRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/ListCalls',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function UploadVoice(\Live\V1\UploadVoiceRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/UploadVoice',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }

    public function GetVoiceFile(\Live\V1\GetVoiceFileRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.live.v1.VoiceService/GetVoiceFile',
            $argument, ['\Live\V1\LiveReply', 'decode'], $metadata, $options);
    }
}
