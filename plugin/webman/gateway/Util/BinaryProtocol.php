<?php

namespace plugin\webman\gateway\Util;
use Workerman\Worker;
use Exception;

class BinaryProtocol
{
    const BYTE_SIZE = 1;
    const SHORT_SIZE = 2;
    const INT_SIZE = 4;
    const LONG_SIZE = 8;
    const FLOAT_SIZE = 4;
    const DOUBLE_SIZE = 8;

    const MSG_ON_CONNECT = 0;
    const MSG_PLAYER_LOGIN = 1;
    const MSG_PLAYER_ONLINE = 2;
    const MSG_PLAYER_MOVE = 3;
    const MSG_CHARACTER = 4;
    const MSG_CHARACTER_CREATE = 5;
    const MSG_ITEM_COLLECTED = 6;
    const MSG_ITEM_SPAWEND = 7;
    const MSG_PLAYER_LIST = 8;
    const MSG_PING = 9;
    const MSG_PONG = 10;
    const MSG_OFFLINE = 11;
    const MSG_ERROR = 255;

    public static function swapEndian16($value) {
        return (($value & 0xFF) << 8) | (($value >> 8) & 0xFF);
    }

    public static function swapEndian32($value) {
        return (($value & 0xFF) << 24) | 
               (($value & 0xFF00) << 8) | 
               (($value >> 8) & 0xFF00) | 
               (($value >> 24) & 0xFF);
    }

    public static function encodeStringWithStatus(int $status, string $message): string
    {
        return pack('C', $status) . self::encodeString($message);
    }

    public static function decodeFloat($payload, &$offset) {
        if ($offset + self::FLOAT_SIZE > strlen($payload)) {
            throw new Exception("浮点数读取失败: 需要 " . self::FLOAT_SIZE . " 字节，剩余 " . (strlen($payload) - $offset));
        }
        $bytes = substr($payload, $offset, self::FLOAT_SIZE);
        // 确保大端序解码
        $value = unpack('G', $bytes)[1]; // 直接使用大端序
        Worker::log("decodeFloat: offset=$offset, bytes=" . bin2hex($bytes) . ", value=$value");
        $offset += self::FLOAT_SIZE;
        return $value;
    }

    public static function encodeFloat($value) {
        $bytes = pack('G', $value); // pack('f') 默认大端序
        Worker::log("encodeFloat: value=$value, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function decodePosition($payload, &$offset) {
        $startOffset = $offset;
        $position = [
            'x' => self::decodeFloat($payload, $offset),
            'y' => self::decodeFloat($payload, $offset),
            'z' => self::decodeFloat($payload, $offset)
        ];
        Worker::log("decodePosition: startOffset=$startOffset, position=" . json_encode($position));
        return $position;
    }

    public static function decodeVector3($payload, &$offset) {
        $startOffset = $offset;
        $vector = [
            'x' => self::decodeFloat($payload, $offset),
            'y' => self::decodeFloat($payload, $offset),
            'z' => self::decodeFloat($payload, $offset)
        ];
        Worker::log("decodeVector3: startOffset=$startOffset, vector=" . json_encode($vector));
        return $vector;
    }

    public static function decodeQuaternion($payload, &$offset) {
        $startOffset = $offset;
        $quaternion = [
            'x' => self::decodeFloat($payload, $offset),
            'y' => self::decodeFloat($payload, $offset),
            'z' => self::decodeFloat($payload, $offset),
            'w' => self::decodeFloat($payload, $offset)
        ];
        Worker::log("decodeQuaternion: startOffset=$startOffset, quaternion=" . json_encode($quaternion));
        return $quaternion;
    }

    public static function encodePosition($x, $y, $z) {
        $bytes = self::encodeFloat($x) . self::encodeFloat($y) . self::encodeFloat($z);
        Worker::log("encodePosition: x=$x, y=$y, z=$z, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function encodeVector3($x, $y, $z) {
        $bytes = self::encodeFloat($x) . self::encodeFloat($y) . self::encodeFloat($z);
        Worker::log("encodeVector3: x=$x, y=$y, z=$z, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function encodeQuaternion($x, $y, $z, $w) {
        $bytes = self::encodeFloat($x) . self::encodeFloat($y) . self::encodeFloat($z) . self::encodeFloat($w);
        Worker::log("encodeQuaternion: x=$x, y=$y, z=$z, w=$w, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function encodeStatus($status) {
        $bytes = pack('C', $status);
        Worker::log("encodeStatus: status=$status, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function decodeStatus($payload, &$offset) {
        if ($offset + self::BYTE_SIZE > strlen($payload)) {
            throw new Exception("状态码读取失败");
        }
        $status = unpack('C', substr($payload, $offset, self::BYTE_SIZE))[1];
        Worker::log("decodeStatus: offset=$offset, status=$status");
        $offset += self::BYTE_SIZE;
        return $status;
    }

    public static function createMessage($msgType, $payload)
    {
        $message = pack('CN', $msgType, strlen($payload)) . $payload;
        Worker::log("createMessage: type=$msgType, payload_len=" . strlen($payload) . ", data=" . bin2hex($message));
        return $message;
    }

    public static function parseMessage($message)
    {
        if (strlen($message) < 5) {
            throw new \Exception("消息长度不足");
        }

        $msgType = unpack('C', substr($message, 0, 1))[1];
        $payloadLength = unpack('N', substr($message, 1, 4))[1];
        Worker::log("parseMessage: msgType=$msgType, payloadLength=$payloadLength, message=" . bin2hex($message));
        $payload = substr($message, 5, $payloadLength);
        if (strlen($payload) !== $payloadLength) {
            throw new \Exception("Payload 长度不匹配: 期望 $payloadLength, 实际 " . strlen($payload));
        }

        return ['msgType' => $msgType, 'payload' => $payload];
    }

    public static function encodeString($string) {
        $length = strlen($string);
        $bytes = pack('n', $length) . $string;
        Worker::log("encodeString: string=$string, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function decodeString($payload, &$offset) {
        if ($offset + self::SHORT_SIZE > strlen($payload)) {
            throw new Exception("字符串长度读取失败");
        }
        $length = unpack('n', substr($payload, $offset, self::SHORT_SIZE))[1];
        $offset += self::SHORT_SIZE;
        
        if ($offset + $length > strlen($payload)) {
            throw new Exception("字符串内容读取失败");
        }
        $string = substr($payload, $offset, $length);
        Worker::log("decodeString: offset=$offset, string=$string");
        $offset += $length;
        return $string;
    }

    public static function encodeInt32($value) {
        $bytes = pack('N', $value);
        Worker::log("encodeInt32: value=$value, bytes=" . bin2hex($bytes));
        return $bytes;
    }

    public static function decodeInt32($payload, &$offset) {
        if ($offset + self::INT_SIZE > strlen($payload)) {
            throw new Exception("32位整数读取失败");
        }
        $value = unpack('N', substr($payload, $offset, self::INT_SIZE))[1];
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        Worker::log("decodeInt32: offset=$offset, value=$value");
        $offset += self::INT_SIZE;
        return $value;
    }

    public static function encodeStringArray($strings) {
        $payload = pack('n', count($strings));
        foreach ($strings as $string) {
            $payload .= self::encodeString($string);
        }
        Worker::log("encodeStringArray: count=" . count($strings) . ", bytes=" . bin2hex($payload));
        return $payload;
    }

    public static function decodeStringArray($payload, &$offset) {
        if ($offset + self::SHORT_SIZE > strlen($payload)) {
            throw new Exception("字符串数组长度读取失败");
        }
        $count = unpack('n', substr($payload, $offset, self::SHORT_SIZE))[1];
        $offset += self::SHORT_SIZE;
        
        $strings = [];
        for ($i = 0; $i < $count; $i++) {
            $strings[] = self::decodeString($payload, $offset);
        }
        Worker::log("decodeStringArray: count=$count, strings=" . json_encode($strings));
        return $strings;
    }

    public static function createErrorMessage($errorMessage)
    {
        $bytes = self::encodeString($errorMessage);
        Worker::log("createErrorMessage: message=$errorMessage, bytes=" . bin2hex($bytes));
        return self::createMessage(self::MSG_ERROR, $bytes);
    }
}