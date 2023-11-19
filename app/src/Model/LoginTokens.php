<?php

namespace App\Model;

use Nepf2\Util\Random;

class LoginTokens extends \Pop\Db\Record
{
    const EXPIRATION_TIME = 20 * 60;
    const TOKEN_LEN_POLL = 64;
    const TOKEN_LEN_LOGIN = 128;

    /*
     * int id
     * int created utctime
     * varchar user_agent
     * varchar poll_token
     * varchar login_token
     * varchar login_name
     * varchar login_password
     */

    public static function Expire(): void
    {
        $q = self::db()->createSql();
        $q->delete(self::table())
            ->where('created < :time');
        self::execute($q, ['time' => time() - self::EXPIRATION_TIME]);
    }

    public static function New(string $userAgent, int $version=2): static
    {
        $token = new static([
            'created' => time(),
            'user_agent' => $userAgent,
            'poll_token' => sprintf('v%d:%s', $version, Random::TokenStr(self::TOKEN_LEN_POLL)),
            'login_token' => Random::TokenStr(self::TOKEN_LEN_LOGIN),
        ]);
        $token->save();
        return $token;
    }

    public function isV1Token(): bool
    {
        return str_starts_with($this->poll_token, 'v1');
    }
}