<?php

namespace App\Model;

use Nepf2\Util\Random;

class AppPasswords extends \Pop\Db\Record\Encoded
{
    const APP_PASSWORD_LEN = 72;

    protected $hashFields      = ['password'];
    protected $hashAlgorithm   = PASSWORD_BCRYPT;
    protected $hashOptions     = ['cost' => 5];

    /*
     * int id
     * int created utctime
     * int last_used utctime
     * varchar user_agent
     * varchar login_name
     * varchar password
     * int user_id User
     */

    public static function New(string $userAgent, Users $user, ?string &$generatedPassword): static
    {
        $generatedPassword = Random::TokenStr(self::APP_PASSWORD_LEN);
        // the client uses this as the user fragment in DAV urls, but gets the display name from OCS endpoint
        // use something that avoids escaping issues
        // TODO: should those be unique for faster auth or stable for nicer URLs?
        $generatedLogin = substr(sha1(sprintf('%d::%s', $user->id, $user->username)), 0, 8);
        $appPassword = new static([
            'created' => time(),
            'last_used' => time(),
            'user_agent' => $userAgent,
            'login_name' => $generatedLogin,
            'password' => $generatedPassword,
            'user_id' => $user->id,
        ]);
        $appPassword->save();
        return $appPassword;
    }
}