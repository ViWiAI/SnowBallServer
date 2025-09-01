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
    private static $targetPositions = [];
    private const LOG_FILE = __DIR__ . '/events.log';

    public static function onWorkerStart($worker)
    {
        self::$characterManager = new CharacterManager();
        Worker::$logFile = self::LOG_FILE;
        Worker::log("Worker {$worker->id} 启动，CharacterManager初始化完成");
    }

    public static function onConnect($client_id)
    {
        try {
            $messagePayload = BinaryProtocol::encodeString("欢迎登录：$client_id");
            $message = BinaryProtocol::createMessage(BinaryProtocol::MSG_ON_CONNECT, $messagePayload);
            $result = Gateway::sendToClient($client_id, $message);
            Worker::log("客户端 $client_id 连接到 Server: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($message));
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
                case BinaryProtocol::MSG_ITEM_COLLECTED:
                    self::handleItemCollected($client_id, $payload);
                    break;
                case BinaryProtocol::MSG_PONG: 
                    self::handlePing($client_id,$payload);
                    break;
                case BinaryProtocol::MSG_OFFLINE: 
                    self::handleOffline($client_id,$payload);
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

    private static function handleOffline($client_id, $payload)
    {
        $offset = 0;
        try {
            $playerId = BinaryProtocol::decodeInt32($payload, $offset);
            $mapId = BinaryProtocol::decodeInt32($payload, $offset);
            Worker::log("客户端 $client_id 玩家 $playerId 发送 MSG_OFFLINE, mapId=$mapId");

            // 从会话中移除玩家
            Gateway::updateSession($client_id, []); // 清空会话数据
            Gateway::leaveGroup($client_id, $mapId); // 退出地图组

            // 广播 MSG_OFFLINE 消息给同一地图的其他客户端
            $responsePayload = BinaryProtocol::encodeInt32($playerId) .
                               BinaryProtocol::encodeInt32($mapId);
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_OFFLINE, $responsePayload);
            $result = Gateway::sendToGroup($mapId, $response, [$client_id]);
            Worker::log("广播玩家 $playerId 下线信息到地图 $mapId, success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("下线消息处理错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 下线消息处理失败: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    private static function handlePing($client_id, $payload)
    {
        Worker::log("客户端 $client_id 回复pong消息, 数据=" . bin2hex($payload));
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

            self::$characterManager->sendCharacterInfo($client_id, $username);

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
            $playerId = BinaryProtocol::decodeInt32($payload, $offset);
            $mapId = BinaryProtocol::decodeInt32($payload, $offset);
            $position = BinaryProtocol::decodePosition($payload, $offset); // 直接为浮点数
            $velocity = BinaryProtocol::decodeVector3($payload, $offset); // 直接为浮点数
            $rotation = BinaryProtocol::decodeQuaternion($payload, $offset);
            $scaleFactor = BinaryProtocol::decodeInt32($payload, $offset);

            Worker::log("玩家 $playerId 上线, mapId=$mapId, position=" . json_encode($position) . ", velocity=" . json_encode($velocity) . ", rotation=" . json_encode($rotation) . ", scaleFactor=$scaleFactor");

            // 绑定 UID 并存储到 $_SESSION
            Gateway::bindUid($client_id, $playerId);
            $_SESSION['playerId'] = $playerId;  // 存储 playerId
            $_SESSION['mapId'] = $mapId;   // 存储 mapId
            $_SESSION['position'] = $position;
            $_SESSION['velocity'] = $velocity;
            $_SESSION['rotation'] = $rotation;
            $_SESSION['scaleFactor'] = $scaleFactor;

            Gateway::joinGroup($client_id, $mapId);

            $playerData = [
                'playerId' => $playerId,
                'mapId' => $mapId,
                'position' => $position,
                'velocity' => $velocity,
                'rotation' => $rotation,
                'scaleFactor' => $scaleFactor,
                'client_id' => $client_id
            ];
            Gateway::updateSession($client_id, $playerData);

            $groupSessions = Gateway::getClientSessionsByGroup($mapId);
            $onlinePlayers = [];
            foreach ($groupSessions as $sessionClientId => $session) {
                if ($sessionClientId != $client_id) {
                    $onlinePlayers[] = [
                        'playerId' => $session['playerId'],
                        'position' => $session['position'],
                        'velocity' => $session['velocity'],
                        'rotation' => $session['rotation'],
                        'scaleFactor' => $session['scaleFactor']
                    ];
                }
            }

            $playersPayload = '';
            foreach ($onlinePlayers as $player) {
                $playersPayload .= BinaryProtocol::encodeInt32($player['playerId']) .
                                  BinaryProtocol::encodeInt32($mapId).
                                  BinaryProtocol::encodePosition($player['position']['x'], $player['position']['y'], $player['position']['z']) .
                                  BinaryProtocol::encodeVector3($player['velocity']['x'], $player['velocity']['y'], $player['velocity']['z']) .
                                  BinaryProtocol::encodeQuaternion($player['rotation']['x'], $player['rotation']['y'], $player['rotation']['z'], $player['rotation']['w']) .
                                  BinaryProtocol::encodeInt32($player['scaleFactor']);
            }
            if (count($onlinePlayers) > 0) {
                $onlinePlayersResponse = BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_LIST, $playersPayload);
                $result = Gateway::sendToClient($client_id, $onlinePlayersResponse);
                Worker::log("向客户端 $client_id 发送在线玩家信息, 人数=" . count($onlinePlayers) . ", success=" . ($result ? 'true' : 'false'));
            }

            $responsePayload = BinaryProtocol::encodeInt32($playerId) .
                              BinaryProtocol::encodeInt32($mapId) .
                              BinaryProtocol::encodePosition($position['x'], $position['y'], $position['z']) .
                              BinaryProtocol::encodeVector3($velocity['x'], $velocity['y'], $velocity['z']) .
                              BinaryProtocol::encodeQuaternion($rotation['x'], $rotation['y'], $rotation['z'], $rotation['w']) .
                              BinaryProtocol::encodeInt32($scaleFactor);
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_ONLINE, $responsePayload);
            $result = Gateway::sendToGroup($mapId, $response, [$client_id]);
            Worker::log("广播玩家 $playerId 上线信息到地图 $mapId, success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("上线请求错误: " . $e->getMessage());
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
            $position = BinaryProtocol::decodePosition($payload, $offset); // 直接为浮点数
            $velocity = BinaryProtocol::decodeVector3($payload, $offset); // 直接为浮点数
            $rotation = BinaryProtocol::decodeQuaternion($payload, $offset);
            $scaleFactor = BinaryProtocol::decodeInt32($payload, $offset);

            Worker::log("玩家 $playerId 移动请求, mapId=$mapId, position=" . json_encode($position) . ", velocity=" . json_encode($velocity) . ", rotation=" . json_encode($rotation) . ", scaleFactor=$scaleFactor");

            // 更新 $_SESSION 数据
            $_SESSION['position'] = $position;
            $_SESSION['velocity'] = $velocity;
            $_SESSION['rotation'] = $rotation;
            $_SESSION['scaleFactor'] = $scaleFactor;

            $session = Gateway::getSession($client_id);
            $currentPosition = $session['position'] ?? ['x' => 0.0, 'y' => 0.0, 'z' => 0.0, 'map_id' => $mapId];

            if (!self::isValidMove($currentPosition, array_merge($position, ['map_id' => $mapId]))) {
                $errorMessage = BinaryProtocol::createErrorMessage("非法移动请求");
                $result = Gateway::sendToClient($client_id, $errorMessage);
                Worker::log("客户端 $client_id 移动非法: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
                return;
            }

            $playerData = [
                'playerId' => $playerId, 
                'mapId' => $mapId,
                'position' => $position,
                'velocity' => $velocity,
                'rotation' => $rotation,
                'scaleFactor' => $scaleFactor,
                'client_id' => $client_id
            ];
            Gateway::updateSession($client_id, $playerData);

            $responsePayload = BinaryProtocol::encodeInt32($playerId) .
                              BinaryProtocol::encodeInt32($mapId) .
                              BinaryProtocol::encodePosition($position['x'], $position['y'], $position['z']) .
                              BinaryProtocol::encodeVector3($velocity['x'], $velocity['y'], $velocity['z']) .
                              BinaryProtocol::encodeQuaternion($rotation['x'], $rotation['y'], $rotation['z'], $rotation['w']) .
                              BinaryProtocol::encodeInt32($scaleFactor);
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_MOVE, $responsePayload);
            $result = Gateway::sendToGroup($mapId, $response, [$client_id]);
            Worker::log("广播玩家 $playerId 移动信息到地图 $mapId, success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("移动请求错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 移动错误: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    private static function isValidMove($current, $new)
    {
        if (!isset($new['x']) || !isset($new['z']) ||
            $new['x'] < 0.0 || $new['x'] > 1000.0 ||
            $new['z'] < 0.0 || $new['z'] > 1000.0) {
            Worker::log("坐标超出地图范围: x={$new['x']}, z={$new['z']}");
            return false;
        }
        return true;
    }

    private static function handleItemCollected($client_id, $payload)
    {
        $offset = 0;
        try {
            $itemType = BinaryProtocol::decodeString($payload, $offset);
            $spawnId = BinaryProtocol::decodeInt32($payload, $offset);

            $session = Gateway::getSession($client_id);
            $playerId = $session['playerId'] ?? 0;
            $mapId = $session['mapId'] ?? 0;
            $scaleFactor = $session['scaleFactor'] ?? 1000;

            $scaleIncrement = 0;
            switch ($itemType) {
                case 'Gem01':
                    $scaleIncrement = 100;
                    break;
                case 'Gem02':
                    $scaleIncrement = 200;
                    break;
                case 'Potion01':
                    $scaleIncrement = 150;
                    break;
                case 'Potion02':
                    $scaleIncrement = 250;
                    break;
                case 'Star01':
                    $scaleIncrement = 300;
                    break;
                case 'Star02':
                    $scaleIncrement = 400;
                    break;
                default:
                    Worker::log("未知道具类型: $itemType");
                    return;
            }

            $newScaleFactor = $scaleFactor + $scaleIncrement;
            $session['scaleFactor'] = $newScaleFactor;
            Gateway::updateSession($client_id, $session);

            Worker::log("玩家 $playerId 收集道具: $itemType, spawnId=$spawnId, 新缩放倍数=$newScaleFactor");

            $responsePayload = BinaryProtocol::encodeInt32($playerId) .
                              BinaryProtocol::encodeInt32($mapId) .
                              BinaryProtocol::encodePosition($session['position']['x'], $session['position']['y'], $session['position']['z']) .
                              BinaryProtocol::encodeVector3($session['velocity']['x'], $session['velocity']['y'], $session['velocity']['z']) .
                              BinaryProtocol::encodeQuaternion($session['rotation']['x'], $session['rotation']['y'], $session['rotation']['z'], $session['rotation']['w']) .
                              BinaryProtocol::encodeInt32($newScaleFactor);
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_PLAYER_MOVE, $responsePayload);
            $result = Gateway::sendToGroup($mapId, $response, [$client_id]);
            Worker::log("广播玩家 $playerId 缩放更新到地图 $mapId, success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));

            $itemCollectedPayload = BinaryProtocol::encodeString($itemType) . BinaryProtocol::encodeInt32($spawnId);
            $itemCollectedResponse = BinaryProtocol::createMessage(BinaryProtocol::MSG_ITEM_COLLECTED, $itemCollectedPayload);
            $result = Gateway::sendToGroup($mapId, $itemCollectedResponse);
            Worker::log("广播道具 $itemType (spawnId=$spawnId) 收集信息到地图 $mapId, success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($itemCollectedResponse));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("道具收集错误: " . $e->getMessage());
            $result = Gateway::sendToClient($client_id, $errorMessage);
            Worker::log("客户端 $client_id 道具收集错误: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    public static function onClose($client_id)
    {
        try {
            // 使用 $_SESSION 获取数据
            $playerId = $_SESSION['playerId'] ?? 0;
            $mapId = $_SESSION['mapId'] ?? 0;

            if ($playerId && $mapId) {
                Worker::log("客户端 $client_id (玩家 $playerId) 断开连接，广播 MSG_OFFLINE");
                // 清空会话并退出组
                Gateway::updateSession($client_id, []);
                Gateway::leaveGroup($client_id, $mapId);
                // 清空 $_SESSION
                $_SESSION = [];

                // 广播 MSG_OFFLINE 消息
                $responsePayload = BinaryProtocol::encodeInt32($playerId) .
                                   BinaryProtocol::encodeInt32($mapId);
                $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_OFFLINE, $responsePayload);
                $result = Gateway::sendToGroup($mapId, $response, [$client_id]);
                Worker::log("广播玩家 $playerId 下线信息到地图 $mapId, success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
            } else {
                Worker::log("客户端 $client_id 断开连接，无有效玩家数据: playerId=$playerId, mapId=$mapId");
            }
        } catch (\Exception $e) {
            Worker::log("客户端 $client_id onClose 处理失败: " . $e->getMessage());
        }
    }
}