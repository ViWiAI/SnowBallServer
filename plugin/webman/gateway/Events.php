<?php

namespace plugin\webman\gateway;

use GatewayWorker\Lib\Gateway;
use Workerman\Worker;
use support\Db;
use plugin\webman\gateway\Util\BinaryProtocol;
use plugin\webman\gateway\Util\ServerTool;
use plugin\webman\gateway\Util\Spawn;
use plugin\webman\gateway\CharacterManager;


class Events
{
    private static $characterManager = null;
    private static $targetPositions = []; // 缓存玩家目标位置
    private const LOG_FILE = __DIR__ . '/events.log'; // 日志文件路径

    public static function onWorkerStart($worker)
    {
        self::$characterManager = new CharacterManager();
        Worker::$logFile = self::LOG_FILE; // 设置日志文件
        Worker::log("Worker {$worker->id} 启动，CharacterManager初始化完成");
        // Spawn::onWorkerStart($worker); // 初始化Spawn逻辑
    }

    public static function onConnect($client_id)
    {
        try {
            $messagePayload = BinaryProtocol::encodeString("欢迎登录：$client_id");
            $message = BinaryProtocol::createMessage(BinaryProtocol::MSG_ON_CONNECT, $messagePayload);
            $result = Gateway::sendToClient($client_id, $message);
            Worker::log("客户端 $client_id 连接，发送欢迎消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($message));
        } catch (\Exception $e) {
            Worker::log("客户端 $client_id 连接错误: " . $e->getMessage());
        }
    }

    public static function onWebSocketConnect($client_id, $data)
    {

    }

    public static function onMessage($client_id, $message)
    {
        $messageSizeKB = strlen($message) / 1024;
        Worker::log(sprintf("收到客户端 %s 消息: 大小=%.2f KB", $client_id, $messageSizeKB));

        try {
            $parsed = BinaryProtocol::parseMessage($message);
            $msgType = $parsed['msgType'];
            $payload = $parsed['payload'];

            switch ($msgType) {
                case BinaryProtocol::MSG_PLAYER_LOGIN:
                    self::handleLogin($client_id, $payload);
                    break;
                case BinaryProtocol::MSG_CHARACTER_CREATE:
                    self::$characterManager->createCharacter($client_id, $payload);
                    break;
                case BinaryProtocol::MSG_PLAYER_ONLINE:
                    self::handlePlayerOnline($client_id, $payload);
                    break;
                case BinaryProtocol::MSG_PLAYER_MOVE:
                    self::handleMoveRequest($client_id, $payload);
                    break;
                default:
                    $errorMessage = BinaryProtocol::createErrorMessage("未知消息类型");
                    $result = Gateway::sendToClient($client_id, $errorMessage);
                    Worker::log("客户端 $client_id 未知消息类型: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
            }
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("消息处理错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 消息处理失败: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }    

    private static function handleLogin($client_id, $payload)
    {
        $offset = 0;
        try {
            $username = BinaryProtocol::decodeString($payload, $offset);
            $password = BinaryProtocol::decodeString($payload, $offset);

            if (empty($username) || empty($password)) {
                $errorMessage = BinaryProtocol::createErrorMessage("缺少必要字段");
                $result = Gateway::sendToClient($client_id, $errorMessage);
                Worker::log("客户端 $client_id 登录失败: 缺少必要字段, 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
                return;
            }

            $account = Db::table('accounts')
                ->where('email', $username)
                ->where('password', $password)
                ->first();

            if (!$account) {
                $errorMessage = BinaryProtocol::createErrorMessage("请输入正确的账号密码");
                $result = Gateway::sendToClient($client_id, $errorMessage);
                Worker::log("客户端 $client_id 登录失败: 账号密码错误, 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
                return;
            }

            self::$characterManager->sendCharacterInfo($client_id,$username);

            // Send original login response with banned status
            $loginPayload = pack('N', $account->banned);
            $loginResponse = BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_LOGIN, $loginPayload);
            $loginResult = Gateway::sendToClient($client_id, $loginResponse);
            Worker::log("客户端 $client_id 登录成功: banned={$account->banned}, 发送登录响应: success=" . ($loginResult ? 'true' : 'false') . ", 数据=" . bin2hex($loginResponse));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("服务器错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 登录错误: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    private static function handlePlayerOnline($client_id, $payload)
    {
        $offset = 0;
        try {
            $playerId = BinaryProtocol::decodeString($payload, $offset);
            $mapId = BinaryProtocol::decodeString($payload, $offset);
            $job = BinaryProtocol::decodeString($payload, $offset);
            $position = BinaryProtocol::decodePosition($payload, $offset);

            if (empty($playerId) || empty($mapId) || empty($job)) {
                $errorMessage = BinaryProtocol::createErrorMessage("player_online 消息缺少必要字段");
                $result = Gateway::sendToClient($client_id, $errorMessage);
                Worker::log("客户端 $client_id 上线失败: 缺少必要字段, 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
                return;
            }

            Db::table('players')->updateOrInsert(
                ['player_id' => $playerId],
                [
                    'map_id' => $mapId,
                    'job' => $job,
                    'x' => $position['x'],
                    'y' => $position['y'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]
            );

            $onlinePayload = BinaryProtocol::encodeString($playerId) .
                             BinaryProtocol::encodeString($mapId) .
                             BinaryProtocol::encodeString($job) .
                             BinaryProtocol::encodePosition($position['x'], $position['y']);

            $result = Gateway::sendToAll(BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_ONLINE, $onlinePayload), null, [$client_id]);
            Worker::log("客户端 $client_id 上线，广播给其他客户端: success=" . ($result ? 'true' : 'false') . ", playerId=$playerId, mapId=$mapId, job=$job, position=" . json_encode($position));

            $result = Gateway::sendToClient($client_id, BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_ONLINE, $onlinePayload));
            Worker::log("客户端 $client_id 上线确认: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($onlinePayload));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("上线处理错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 上线错误: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    private static function handleMoveRequest($client_id, $payload)
    {
        $offset = 0;
        try {
            $playerId = BinaryProtocol::decodeInt32($payload, $offset);
            $mapId = BinaryProtocol::decodeInt32($payload, $offset);
            $position = BinaryProtocol::decodePosition($payload, $offset);

            $responsePayload = BinaryProtocol::encodeInt32($playerId) . BinaryProtocol::encodePosition($position['x'], $position['y'],$position['z']);
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_MOVE, $responsePayload);
            $result = Gateway::sendToGroup($client_id, $response);
            Worker::log("客户端 $client_id 移动确认: playerId=$playerId, mapId=$mapId, position=" . json_encode($position) . ", success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("移动请求错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 移动错误: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    private static function isValidMove($current, $new)
    {
        $distance = abs($new['x'] - $current['x']) + abs($new['y'] - $current['y']);
        $maxMoveDistance = 5;

        if ($distance > $maxMoveDistance) {
            return false;
        }

        $tile = Db::table('map_tiles')
            ->where('map_id', $current['map_id'])
            ->where('x', $new['x'])
            ->where('y', $new['y'])
            ->first();

        return $tile && !$tile->is_obstacle;
    }


    public static function onClose($client_id)
    {
        Worker::log("客户端 $client_id 断开连接");
    }
}
