<?php

namespace plugin\webman\gateway;

use GatewayWorker\Lib\Gateway;
use Workerman\Worker;
use Workerman\Timer;
use support\Db;
use plugin\webman\gateway\Util\BinaryProtocol;

class Spawn
{
    private static $mapId = 1; // 默认地图ID，可配置
    private static $timerId = null; // 定时器ID
    private const CHECK_INTERVAL = 5; // 检查刷新间隔（秒）

    /**
     * 在服务器启动时初始化
     */
    public static function onWorkerStart($worker)
    {
        Worker::log("Spawn类初始化，Worker {$worker->id}");

        // 启动定时器，定期检查道具刷新
        self::$timerId = Timer::add(self::CHECK_INTERVAL, function () {
            self::checkAndRespawnItems();
        });

        // 广播初始活跃道具
        self::broadcastActiveItems();
    }

    /**
     * 处理道具收集
     * @param int $clientId 客户端ID
     * @param string $payload 二进制负载
     */
    public static function handleItemCollected($clientId, $payload)
    {
        $offset = 0;
        try {
            $itemType = BinaryProtocol::decodeString($payload, $offset);
            $spawnId = BinaryProtocol::decodeInt32($payload, $offset); // 使用spawn_id而非item_id

            // 验证道具
            $spawn = Db::table('SpawnList')
                ->where('spawn_id', $spawnId)
                ->where('status', 'active')
                ->first();

            if (!$spawn) {
                $errorMessage = BinaryProtocol::createErrorMessage("道具不存在或已被收集");
                $result = Gateway::sendToClient($clientId, $errorMessage);
                Worker::log("客户端 $clientId 收集道具失败: spawn_id=$spawnId, type=$itemType, 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
                return;
            }

            // 获取道具的respawn_time
            $item = Db::table('Items')->where('item_id', $spawn->item_id)->first();
            $respawnTime = $item->respawn_time;

            // 更新道具状态和刷新时间
            $nextRespawn = date('Y-m-d H:i:s', strtotime("+$respawnTime seconds"));
            Db::table('SpawnList')
                ->where('spawn_id', $spawnId)
                ->update([
                    'status' => 'collected',
                    'next_respawn_time' => $nextRespawn,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

            // 广播道具收集消息
            $responsePayload = BinaryProtocol::encodeString($itemType) .
                              BinaryProtocol::encodeInt32($spawnId);
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_ITEM_COLLECTED, $responsePayload);
            $result = Gateway::sendToAll($response);
            Worker::log("客户端 $clientId 收集道具: spawn_id=$spawnId, type=$itemType, 广播成功: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
        } catch (\Exception $e) {
            $errorMessage = BinaryProtocol::createErrorMessage("道具收集错误: " . $e->getMessage());
            $result = Gateway::sendToClient($clientId, $errorMessage);
            Worker::log("客户端 $clientId 收集道具错误: " . $e->getMessage() . ", 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
        }
    }

    /**
     * 检查并刷新道具
     */
    private static function checkAndRespawnItems()
    {
        try {
            $currentTime = date('Y-m-d H:i:s');
            $toRespawn = Db::table('SpawnList')
                ->where('status', 'collected')
                ->where('next_respawn_time', '<=', $currentTime)
                ->get();

            foreach ($toRespawn as $spawn) {
                // 更新道具状态
                Db::table('SpawnList')
                    ->where('spawn_id', $spawn->spawn_id)
                    ->update([
                        'status' => 'active',
                        'next_respawn_time' => null,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                // 获取道具名称
                $item = Db::table('Items')->where('item_id', $spawn->item_id)->first();
                $itemType = $item->name;

                // 广播道具刷新消息
                $responsePayload = BinaryProtocol::encodeInt32($spawn->spawn_id) .
                                  BinaryProtocol::encodeString($itemType) .
                                  BinaryProtocol::encodeFloat($spawn->x) .
                                  BinaryProtocol::encodeFloat($spawn->y) .
                                  BinaryProtocol::encodeFloat($spawn->z);
                $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_ITEM_SPAWNED, $responsePayload);
                $result = Gateway::sendToAll($response);
                Worker::log("刷新道具: spawn_id={$spawn->spawn_id}, type=$itemType, position=({$spawn->x},{$spawn->y},{$spawn->z}), 广播成功: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
            }
        } catch (\Exception $e) {
            Worker::log("检查道具刷新失败: " . $e->getMessage());
        }
    }

    /**
     * 广播初始活跃道具
     */
    private static function broadcastActiveItems()
    {
        try {
            $activeItems = Db::table('SpawnList')
                ->where('map_id', self::$mapId)
                ->where('status', 'active')
                ->get();

            foreach ($activeItems as $spawn) {
                $item = Db::table('Items')->where('item_id', $spawn->item_id)->first();
                $itemType = $item->name;

                $responsePayload = BinaryProtocol::encodeInt32($spawn->spawn_id) .
                                  BinaryProtocol::encodeString($itemType) .
                                  BinaryProtocol::encodeFloat($spawn->x) .
                                  BinaryProtocol::encodeFloat($spawn->y) .
                                  BinaryProtocol::encodeFloat($spawn->z);
                $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_ITEM_SPAWNED, $responsePayload);
                $result = Gateway::sendToAll($response);
                Worker::log("广播初始道具: spawn_id={$spawn->spawn_id}, type=$itemType, position=({$spawn->x},{$spawn->y},{$spawn->z}), success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
            }
        } catch (\Exception $e) {
            Worker::log("广播初始道具失败: " . $e->getMessage());
        }
    }
}