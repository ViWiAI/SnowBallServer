<?php

namespace plugin\webman\gateway;

use support\Db;

class SpawnInitializer
{
    /**
     * 初始化SpawnList表，根据Items表生成道具分布
     * @param int $mapId 地图ID
     * @param float $mapWidth 地图宽度（默认1000）
     * @param float $mapHeight 地图高度（默认1000）
     * @param float $minDistance 道具间最小距离（默认1米）
     */
    public static function initSpawnList($mapId, $mapWidth = 1000.0, $mapHeight = 1000.0, $minDistance = 1.0)
    {
        try {
            // 清空指定地图的SpawnList
            Db::table('SpawnList')->where('map_id', $mapId)->delete();
            Worker::log("清空地图 $mapId 的SpawnList表");

            // 获取所有道具配置
            $items = Db::table('Items')->get();
            if ($items->isEmpty()) {
                Worker::log("Items表为空，无法生成SpawnList");
                return;
            }

            $mapArea = $mapWidth * $mapHeight;
            $existingPositions = [];

            foreach ($items as $item) {
                $itemId = $item->item_id;
                $spawnCount = $item->spawn_count;
                $density = $item->density;
                $calculatedCount = min($spawnCount, floor($mapArea * $density)); // 取配置数量和密度计算的最小值

                Worker::log("为道具 {$item->name} (ID: $itemId) 生成 $calculatedCount 个实例");

                for ($i = 0; $i < $calculatedCount; $i++) {
                    $position = self::getValidSpawnPosition($mapWidth, $mapHeight, $minDistance, $existingPositions);
                    if ($position === null) {
                        Worker::log("无法为道具 {$item->name} (ID: $itemId) 找到有效位置，跳过第 " . ($i + 1) . " 个");
                        continue;
                    }

                    Db::table('SpawnList')->insert([
                        'item_id' => $itemId,
                        'map_id' => $mapId,
                        'x' => $position['x'],
                        'y' => 0.5, // 固定高度
                        'z' => $position['z'],
                        'status' => 'active',
                        'next_respawn_time' => null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                    $existingPositions[] = $position;
                    Worker::log("生成道具实例: item_id=$itemId, map_id=$mapId, position=({$position['x']}, 0.5, {$position['z']})");
                }
            }

            Worker::log("地图 $mapId 的SpawnList初始化完成，共生成 " . count($existingPositions) . " 个道具实例");
        } catch (\Exception $e) {
            Worker::log("初始化SpawnList失败: " . $e->getMessage());
        }
    }

    /**
     * 生成有效生成位置（均匀分布，避免重叠）
     * @param float $mapWidth 地图宽度
     * @param float $mapHeight 地图高度
     * @param float $minDistance 最小距离
     * @param array $existingPositions 已存在的道具位置
     * @return array|null ['x' => float, 'z' => float] 或 null
     */
    private static function getValidSpawnPosition($mapWidth, $mapHeight, $minDistance, &$existingPositions)
    {
        $maxAttempts = 50;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $x = mt_rand(1000, $mapWidth * 1000) / 1000.0; // 1到1000
            $z = mt_rand(1000, $mapHeight * 1000) / 1000.0;
            $position = ['x' => $x, 'z' => $z];

            $isValid = true;
            foreach ($existingPositions as $existing) {
                $distance = sqrt(pow($position['x'] - $existing['x'], 2) + pow($position['z'] - $existing['z'], 2));
                if ($distance < $minDistance) {
                    $isValid = false;
                    break;
                }
            }

            if ($isValid) {
                return $position;
            }
        }

        return null;
    }
}