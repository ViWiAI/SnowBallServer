<?php
namespace plugin\webman\gateway;

use GatewayWorker\Lib\Gateway;
use Workerman\MySQL\Connection;
use Workerman\Worker;
use support\Db;
use plugin\webman\gateway\Util\BinaryProtocol;
use plugin\webman\gateway\Util\ServerTool;

class CharacterManager
{
    private $valid_roles = ['Warrior', 'Mage', 'Hunter', 'Rogue', 'Priest'];

    public function createCharacter($client_id, $payload)
    {
        $offset = 0;
        try {
            // 解码消息
            $name = BinaryProtocol::decodeString($payload, $offset);
            $account_name = BinaryProtocol::decodeString($payload, $offset);
            echo "$name -- $account_name\n";
            // 验证必要字段
            if (empty($name)|| empty($account_name)) {
                $this->sendError($client_id, "缺少必要字段");
                return;
            }

            // 验证 account_name 是否存在于 accounts 表
            $characterCount = Db::table('characters')
                ->where('account_name', $account_name)
                ->count();

            if ($characterCount >= 1) {
                $this->sendError($client_id, "最多创建1个角色");
                return;
            }

            // 检查角色名是否重复
            $account = Db::table('characters')
                ->where('name', $name)
                ->first();

            if ($account) {
                $this->sendError($client_id, "角色名称已经存在");
                return;
            }

            // 设置角色初始值
            $character_data = $this->getCharacterData($name, $account_name);

            // 插入角色记录
            $character_id = Db::table('characters')->insertGetId($character_data);

            if($character_id){
                self::sendCharacterInfo($client_id,$account_name);
            }
            
            // 发送成功响应
            $messagePayload = BinaryProtocol::encodeStringWithStatus(1, "创建角色成功！ID: {$character_id}");
            $response = BinaryProtocol::createMessage(BinaryProtocol::MSG_CHARACTER_CREATE, $messagePayload);
            $result = Gateway::sendToClient($client_id, $response);
            Worker::log("客户端 $client_id 创建角色成功: name={$name}, role={$role}, character_id={$character_id}, 发送响应: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($response));
        } catch (\Exception $e) {
            $this->sendError($client_id, "服务器错误: " . $e->getMessage());
        }
    }

    public function sendCharacterInfo($client_id,$account_name)
    {
        $character = DB::table('characters')
                ->where('account_name', $account_name)
                ->first();
        if($character){
            $characterPayload = BinaryProtocol::encodeStatus(1) . 
            BinaryProtocol::encodeInt32($character->id).
            BinaryProtocol::encodeString($character->name).
            BinaryProtocol::encodeInt32($character->lvl).
            BinaryProtocol::encodeInt32($character->curHP).
            BinaryProtocol::encodeInt32($character->maxHP).
            BinaryProtocol::encodeInt32($character->curMP).
            BinaryProtocol::encodeInt32($character->maxMP);
            $result = Gateway::sendToClient($client_id, BinaryProtocol::createMessage(BinaryProtocol::MSG_CHARACTER, $characterPayload));
            Worker::log("客户端 $client_id 角色信息: name={$character->name}, success=" . ($result ? 'true' : 'false'));
        }else{
            $result = Gateway::sendToClient($client_id, BinaryProtocol::createMessage(BinaryProtocol::MSG_CHARACTER, BinaryProtocol::encodeStringWithStatus(2,"账号没有角色信息")));
            Worker::log("客户端 $client_id 没有角色信息}, success=" . ($result ? 'true' : 'false'));
        }
    } 

    private function getCharacterData($name, $account_name)
    {
        $character_data = [
            'account_name' => $account_name,
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'), // 添加创建时间
            'updated_at' => date('Y-m-d H:i:s')  // 添加更新时间
        ];
        return $character_data;
    }

    private function sendError($client_id, $message)
    {
        $errorMessage = BinaryProtocol::createMessage(
            BinaryProtocol::MSG_CHARACTER_CREATE,
            BinaryProtocol::encodeStringWithStatus(2,$message)
        );
        $result = Gateway::sendToClient($client_id, $errorMessage);
        Worker::log("客户端 $client_id 创建角色失败: $message, 发送错误消息: success=" . ($result ? 'true' : 'false') . ", 数据=" . bin2hex($errorMessage));
    }
}