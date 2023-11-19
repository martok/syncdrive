<?php

namespace App\Dav;

use App\Model;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Session\Session;
use Sabre\HTTP\Auth;

class Identity
{
    const TYPE_UNAUTHENTICATED = 0;
    const TYPE_USER = 1;
    const TYPE_SHARE = 2;

    private string $realm = 'Login';
    public int $type = self::TYPE_UNAUTHENTICATED;
    public ?Model\Users $user = null;
    public ?string $appLogin = null;
    public ?Model\InodeShares $share = null;

    public function __construct(string $realm)
    {
        $this->realm = $realm;
    }

    public static function System(): static
    {
        $ident = new static('');
        $ident->type = self::TYPE_UNAUTHENTICATED;
        return $ident;
    }

    public static function User(Model\Users $user): static
    {
        $ident = new static('');
        $ident->type = self::TYPE_USER;
        $ident->user = $user;
        return $ident;
    }

    public static function Share(Model\InodeShares $share): static
    {
        $ident = new static('');
        $ident->type = self::TYPE_SHARE;
        $ident->share = $share;
        return $ident;
    }

    /**
     * Try to identify the user from request parameters.
     * Accepts (in order):
     *   - Auth:Basic
     *   - Current session via cookie
     *
     * $urlLogin is verified as:
     *   - same as credential when using AppPassword login
     *   - same as credential or userID when using User
     *
     * @param Request $request request object
     * @param Response $response response object
     * @param Session $session session object
     * @param string $urlLogin login name URL fragment, must match username used in Basic auth
     * @return bool if an identity was found
     */
    public function initRequest(Request $request, Response $response, Session $session, string $urlLogin): bool
    {
        $basic = new Auth\Basic($this->realm, $request, $response);
        if ($cred = $basic->getCredentials()) {
            [$login, $pass] = $cred;
            if ($user = $this->checkLoginUser($login, $urlLogin, $pass)) {
                $this->type = self::TYPE_USER;
                $this->user = $user;
                return true;
            }
            if ($user = $this->checkLoginApp($login, $urlLogin, $pass)) {
                $this->type = self::TYPE_USER;
                $this->user = $user;
                $this->appLogin = $login;
                return true;
            }
        }

        if (!$request->hasHeader('Authorization') && $this->initSession($session))
            return true;

        return false;
    }

    public function initApp(Request $request, Response $response, ?string $urlLogin = null): bool
    {
        $basic = new Auth\Basic($this->realm, $request, $response);
        if ($cred = $basic->getCredentials()) {
            [$login, $pass] = $cred;
            if (($user = $this->checkLoginApp($login, $urlLogin, $pass))) {
                $this->type = self::TYPE_USER;
                $this->user = $user;
                $this->appLogin = $login;
                return true;
            }
        }
        return false;
    }

    protected function checkLoginUser(string $login, ?string $urlLogin, string $password): ?Model\Users
    {
        $user = Model\Users::findOne(['username' => $login]);
        if (empty($user->id))
            return null;
        if ((is_numeric($urlLogin) && (int)$urlLogin === $user->id) ||
            ($urlLogin === $login)) {
            if ($user->verify('password', $password))
                return $user;
        }
        return null;
    }

    protected function checkLoginApp(string $login, ?string $urlLogin, string $password): ?Model\Users
    {
        if (!is_null($urlLogin) && ($login !== $urlLogin))
            return null;
        $apps = Model\AppPasswords::findBy(['login_name' => $login]);
        foreach ($apps as $app) {
            if (!$app->verify('password', $password))
                continue;
            $user = Model\Users::findOne(['id' => $app->user_id]);
            if (empty($user->id))
                continue;
            $app->last_used = time();
            $app->save();
            return $user;
        }
        return null;
    }

    public function initSession(Session $session): bool
    {
        if ($session->started() && isset($session->userid)) {
            $this->type = self::TYPE_USER;
            $this->user = $session->user;
            return true;
        }

        return false;
    }

    public function initShare(Session $session, Model\InodeShares $share, ?string $password, bool $isBasicAuth): bool
    {
        // no password required
        if (empty($share->password))
            goto auth_success;

        // session-stored auth only works if there is a session
        if ($session->started()) {
            if (!isset($session->publicLogins))
                $session->publicLogins = [];
            $saved = &$session->publicLogins;

            // if we have a form password, that overrides and clears passwords on session
            if ($password && !$isBasicAuth) {
                unset($saved[$share->token]);
                if (!password_verify($password, $share->password))
                    goto auth_failure;
                // if the login was successful, store it in a way that invalidates if the password is changed
                $saved[$share->token] = $share->password;
                $session->resetCookie();
                goto auth_success;
            }

            // passwords on session override HTTP (allows token:dummy@host for BrowserViews that can upload)
            if (isset($saved[$share->token])) {
                if ($saved[$share->token] === $share->password) {
                    $session->resetCookie();
                    goto auth_success;
                }
                // if we have one saved, but the share's password changed, delete and fall through to Basic HTTP
                unset($saved[$share->token]);
            }
        }

        // HTTP auth must match exactly and does not get saved
        if ($isBasicAuth) {
            if (password_verify($password, $share->password)) {
                goto auth_success;
            } else {
                goto auth_failure;
            }
        }

        // no good credentials, fall through to failure

        auth_failure:
            $this->type = self::TYPE_UNAUTHENTICATED;
            return false;
        auth_success:
            $this->type = self::TYPE_SHARE;
            $this->share = $share;
            return true;
    }

    public function sendChallenge(Request $request, Response $response)
    {
        $basic = new Auth\Basic($this->realm, $request, $response);
        $basic->requireLogin();
    }

    public function getUserId(): ?int
    {
        if ($this->type !== Identity::TYPE_USER)
            return null;
        return $this->user->id;
    }

}