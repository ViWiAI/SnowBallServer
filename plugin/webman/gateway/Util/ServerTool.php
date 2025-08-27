<?php

namespace plugin\webman\gateway\Util;
use Workerman\Worker;
class ServerTool
{
    /**
     * Convert role string to job ID
     * @param string $role Role name from database
     * @return int Job ID for protocol
     */
    public static function convertRoleToRoleId($role) {
        $roleMap = [
            'Warrior' => 1,
            'Mage' => 2,
            'Hunter' => 3,
            'Rogue' => 4,
            'Priest' =>5,
            // Add more role mappings as needed
        ];
        echo "职业=：$role, $roleMap[$role]\n";
        return isset($roleMap[$role]) ? $roleMap[$role] : 0; // Default to 0 if role not found
    }

}