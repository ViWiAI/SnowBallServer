<?php
/*
协议使用示例
服务端发送玩家列表 (PHP)
php
// 准备玩家数据
$players = [
    ['playerId' => 'player1', 'name' => '玩家1', 'level' => 10, 'job' => 1, 'x' => 100, 'y' => 200],
    ['playerId' => 'player2', 'name' => '玩家2', 'level' => 15, 'job' => 2, 'x' => 150, 'y' => 250]
];

// 编码玩家列表
$payload = BinaryMessage::encodeInt32(count($players));
foreach ($players as $player) {
    $payload .= BinaryMessage::encodePlayerInfo(
        $player['playerId'],
        $player['name'],
        $player['level'],
        $player['job'],
        $player['x'],
        $player['y']
    );
}

// 发送消息
$message = BinaryMessage::createMessage(BinaryMessage::MSG_PLAYER_LIST, $payload);
客户端处理玩家列表 (C#)
csharp
private void HandlePlayerList(byte[] payload)
{
    int offset = 0;
    try
    {
        int playerCount = BinaryProtocol.DecodeInt32(payload, ref offset);
        List<PlayerInfo> players = new List<PlayerInfo>();
        
        for (int i = 0; i < playerCount; i++)
        {
            players.Add(BinaryProtocol.DecodePlayerInfo(payload, ref offset));
        }
        
        // 更新游戏中的玩家显示
        PlayerManager.Instance.UpdatePlayerList(players);
    }
    catch (Exception e)
    {
        Debug.LogError($"处理玩家列表失败: {e.Message}");
    }
}
协议文档
1. 基础数据类型
类型	大小(字节)	编码格式	描述
Byte	1	C	无符号字节
Status	1	C	状态码(0-255)
Short	2	n	无符号短整型(大端序)
Int32	4	N	有符号32位整数(大端序)
Float	4	G	IEEE 754单精度浮点数(大端序)
String	2+n	n + 内容	UTF-8字符串(前2字节为长度)
Position	8	N + N	两个32位整数(x,y)
2. 复合数据类型
玩家信息(PlayerInfo)
字段	类型	描述
playerId	String	玩家唯一ID
name	String	玩家显示名称
level	Int32	玩家等级
job	Int32	职业编号
position	Position	玩家坐标
字符串数组(StringArray)
字段	类型	描述
count	Short	数组元素数量
items	String[]	字符串数组内容
3. 消息结构
所有消息采用统一结构：

text
消息头(5字节):
  - 消息类型(1字节)
  - 负载长度(4字节, 大端序)
消息体(n字节):
  - 实际负载数据
最佳实践建议
严格校验：所有解码操作必须检查数据长度

资源释放：C#端使用BufferPool后要及时释放

日志记录：关键编解码步骤添加详细日志

版本兼容：考虑未来协议升级的兼容性

单元测试：为所有编解码函数编写测试用例

这套协议设计覆盖了游戏开发中常见的通信需求，包括玩家信息同步、位置更新、状态通知等场景。根据实际游戏需求，可以进一步扩展更多数据类型和复合结构。
*/
namespace plugin\webman\gateway\Util;
use Workerman\Worker;
use Exception; // 显式导入内置 Exception 类

class BinaryProtocol
{

    const BYTE_SIZE = 1;
    const SHORT_SIZE = 2;
    const INT_SIZE = 4;
    const LONG_SIZE = 8;
    const FLOAT_SIZE = 4;
    const DOUBLE_SIZE = 8;


    // 消息类型定义
    const MSG_ON_CONNECT = 0;
    const MSG_PLAYER_LOGIN = 1;
    const MSG_PLAYER_ONLINE = 2;
    const MSG_PLAYER_MOVE = 3;
    const MSG_CHARACTER = 4;
    const MSG_CHARACTER_CREATE = 5;
    const MSG_ITEM_COLLECTED = 6;
    const MSG_ITEM_SPAWEND = 7;
    
    
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

    /**
     * 编码状态码(1字节)
     * @param int $status 状态码(0-255)
     * @return string 1字节二进制数据
     */
    public static function encodeStatus($status) {
        return pack('C', $status);
    }

    /**
     * 解码状态码
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return int 解码后的状态码
     * @throws Exception 如果数据不完整
     */
    public static function decodeStatus($payload, &$offset) {
        if ($offset + self::BYTE_SIZE > strlen($payload)) {
            throw new Exception("状态码读取失败");
        }
        $status = unpack('C', substr($payload, $offset, self::BYTE_SIZE))[1];
        $offset += self::BYTE_SIZE;
        return $status;
    }

    public static function createMessage($msgType, $payload)
    {
        $message = pack('CN', $msgType, strlen($payload)) . $payload;
        echo "createMessage: type=$msgType, payload_len=" . strlen($payload) . ", data=" . bin2hex($message) . "\n";
        return $message;
    }

    public static function parseMessage($message)
    {
        if (strlen($message) < 5) {
            throw new \Exception("消息长度不足");
        }

        $msgType = unpack('C', substr($message, 0, 1))[1];
        $payloadLength = unpack('N', substr($message, 1, 4))[1];
        echo "msgType=$msgType, payloadLength=$payloadLength, message=" . bin2hex($message) . "\n";
        $payload = substr($message, 5, $payloadLength);
        if (strlen($payload) !== $payloadLength) {
            throw new \Exception("Payload 长度不匹配: 期望 $payloadLength, 实际 " . strlen($payload));
        }

        return ['msgType' => $msgType, 'payload' => $payload];
    }

    /**
     * 编码字符串
     * @param string $string 要编码的字符串
     * @return string 编码后的二进制数据 (2字节长度 + 字符串内容)
     */
    public static function encodeString($string) {
        $length = strlen($string);
        return pack('n', $length) . $string;
    }

    /**
     * 解码字符串
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return string 解码后的字符串
     * @throws Exception 如果数据不完整
     */
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
        $offset += $length;
        return $string;
    }

    /**
     * 编码32位整数
     * @param int $value 要编码的整数
     * @return string 4字节二进制数据
     */
    public static function encodeInt32($value) {
        return pack('N', $value);
    }

    /**
     * 解码32位整数
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return int 解码后的整数
     * @throws Exception 如果数据不完整
     */
    public static function decodeInt32($payload, &$offset) {
        if ($offset + self::INT_SIZE > strlen($payload)) {
            throw new Exception("32位整数读取失败");
        }
        $value = unpack('N', substr($payload, $offset, self::INT_SIZE))[1];
        $offset += self::INT_SIZE;
        
        // 处理有符号整数
        if ($value >= 0x80000000) {
            $value -= 0x100000000;
        }
        return $value;
    }

    /**
     * 编码单精度浮点数
     * @param float $value 要编码的浮点数
     * @return string 4字节二进制数据
     */
    public static function encodeFloat($value) {
        return pack('G', $value);
    }

    /**
     * 解码单精度浮点数
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return float 解码后的浮点数
     * @throws Exception 如果数据不完整
     */
    public static function decodeFloat($payload, &$offset) {
        if ($offset + self::FLOAT_SIZE > strlen($payload)) {
            throw new Exception("浮点数读取失败");
        }
        $value = unpack('G', substr($payload, $offset, self::FLOAT_SIZE))[1];
        $offset += self::FLOAT_SIZE;
        return $value;
    }

    /**
     * 编码坐标位置(两个32位整数)
     * @param int $x X坐标
     * @param int $y Y坐标
     * @return string 8字节二进制数据
     * @throws Exception 如果坐标不是整数
     */
    public static function encodePosition($x, $y,$z) {
        if (!is_int($x) || !is_int($y) || !is_int($z)) {
            throw new Exception("encodePosition: 坐标必须为整数, x=$x, y=$y, z=$z");
        }
        return self::encodeInt32($x) . self::encodeInt32($y) . self::encodeInt32($z);
    }

    /**
     * 解码坐标位置
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return array ['x'=>int, 'y'=>int] 解码后的坐标
     * @throws Exception 如果数据不完整
     */
    public static function decodePosition($payload, &$offset) {
        $x = self::decodeInt32($payload, $offset);
        $y = self::decodeInt32($payload, $offset);
        $z = self::decodeInt32($payload, $offset);
        return ['x' => $x, 'y' => $y,'z' => $z];
    }

    /**
     * 编码字符串数组
     * @param array $strings 字符串数组
     * @return string 编码后的二进制数据 (2字节数量 + 每个字符串)
     */
    public static function encodeStringArray($strings) {
        $payload = pack('n', count($strings));
        foreach ($strings as $string) {
            $payload .= self::encodeString($string);
        }
        return $payload;
    }

    /**
     * 解码字符串数组
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return array 解码后的字符串数组
     * @throws Exception 如果数据不完整
     */
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
        return $strings;
    }

    public static function createErrorMessage($errorMessage)
    {
        return self::createMessage(self::MSG_ERROR, self::encodeString($errorMessage));
    }

    /**
     * 编码玩家信息
     * @param string $playerId 玩家ID
     * @param string $name 玩家名称
     * @param int $level 玩家等级
     * @param int $job 职业
     * @param int $x 坐标X
     * @param int $y 坐标Y
     * @return string 编码后的二进制数据
     */
    public static function encodePlayerInfo($playerId, $name, $level, $job, $x, $y) {
        return self::encodeString($playerId) . 
            self::encodeString($name) . 
            self::encodeInt32($level) . 
            self::encodeInt32($job) . 
            self::encodePosition($x, $y);
    }

    /**
     * 解码玩家信息
     * @param string $payload 二进制数据
     * @param int &$offset 当前读取偏移量(会被修改)
     * @return array 解码后的玩家信息
     * @throws Exception 如果数据不完整
     */
    public static function decodePlayerInfo($payload, &$offset) {
        return [
            'playerId' => self::decodeString($payload, $offset),
            'name' => self::decodeString($payload, $offset),
            'level' => self::decodeInt32($payload, $offset),
            'role' => self::decodeInt32($payload, $offset),
            'position' => self::decodePosition($payload, $offset)
        ];
    }

    /**
     * Encode character information
     * @param string $characterId Character ID
     * @param string $name Character name
     * @param int $level Character level
     * @param int $job Character job/role ID
     * @param int $skinId Character skin ID
     * @return string Encoded binary data
     */
    public static function encodeCharacterInfo($characterId, $name, $level, $role, $skinId,$mapId,$x,$y) {
        return self::encodeInt32($characterId) .
               self::encodeString($name) .
               self::encodeInt32($level) .
               self::encodeInt32($role) .
               self::encodeInt32($skinId).
               self::encodeInt32($mapId).
               self::encodeInt32($x).
               self::encodeInt32($y);
    }

    /**
     * Decode character information
     * @param string $payload Binary data
     * @param int &$offset Current read offset (will be modified)
     * @return array Decoded character information
     * @throws Exception If data is incomplete
     */
    public static function decodeCharacterInfo($payload, &$offset) {
        return [
            'characterId' => self::decodeString($payload, $offset),
            'name' => self::decodeString($payload, $offset),
            'level' => self::decodeInt32($payload, $offset),
            'role' => self::decodeInt32($payload, $offset),
            'skinId' => self::decodeInt32($payload, $offset),
            'mapId' => self::decodeInt32($payload, $offset),
            'x' => self::decodeInt32($payload, $offset),
            'y' => self::decodeInt32($payload, $offset),
        ];
    }
}