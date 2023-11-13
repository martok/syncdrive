<?php

namespace App\Controller;

use App\Model;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Template\Validation;

#[Auto\RoutePrefix('/user')]
class UserController extends Base
{
    #[Auto\Route('/login', method: "GET")]
    public function loginForm(Response $res, Request $req)
    {
        // Validate session: not logged in
        if ($this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $view = $this->initTemplateView('user_sign_in.twig');
        $res->setBody($view->render());
    }

    #[Auto\Route('/login', method: "POST")]
    public function login(Response $res, Request $req)
    {
        // Validate session: not logged in
        if ($this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $view = $this->initTemplateView('user_sign_in.twig');
        $username = $req->str('username');
        $password = $req->str('password');
        $validation = [];
        $view->set('form.username', $username);
        // Validate login
        $success = (new Validation($validation))
                    ->test('username',
                           ($user = Model\Users::findOne(['username' => $username])) && !empty($user->id)
                           && $user->verify('password', $password),
                           'Username & Password don\'t match')
                    ->succeeded();
        if (!$success) {
            $view->set('validation', $validation);
            $res->setBody($view->render());
            return;
        }
        // Update password storage
        // TODO: better to only do it when something changed, but Encoded doesn't expose the parameters
        $user->password = $password;
        $user->save();
        // Log in user and redirect
        $this->setUserId($user->id);
        if (!empty($this->session->login_flow_redirect)) {
            // if we came here from an app login flow, go back
            $res->redirect($this->session->login_flow_redirect);
            unset($this->session->login_flow_redirect);
        } else {
            $res->redirect('/');
        }
    }

    #[Auto\Route('/logout')]
    public function logout(Response $res, Request $req)
    {
        if ($this->isLoggedIn()) {
            $this->setUserId(null);
            $this->session->destroy();
        }
        $res->redirect('/');
    }

    #[Auto\Route('/signup', method: 'GET')]
    public function signupForm(Response $res, Request $req)
    {
        // Validate session: not logged in
        if ($this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $view = $this->initTemplateView('user_sign_up.twig');
        $res->setBody($view->render());
    }

    #[Auto\Route('/signup', method: 'POST')]
    public function signup(Response $res, Request $req)
    {
        // Validate session: not logged in
        if ($this->isLoggedIn()) {
            $res->redirect('/');
            return;
        }
        $view = $this->initTemplateView('user_sign_up.twig');
        // Validate input
        $username = $req->str('username');
        $passwords = $req->arr('password');
        $validation = [];
        $view->set('form.username', $username);
        $view->set('form.password', $passwords[0] ?? '');
        $success = (new Validation($validation))
                    ->test('username',
                           strlen($username) && filter_var($username, FILTER_VALIDATE_EMAIL),
                           'Invalid mail address!')
                    ->test('username',
                           !isset(Model\Users::findOne(['username' => $username])->id),
                           'Mail address already registered!')
                    ->test('password2',
                           2 === count($passwords) && $passwords[0] === $passwords[1],
                           'Passwords do not match!')
                    ->test('password',
                           2 === count($passwords) && strlen($passwords[0]) >= 8,
                           'Password too short!')
                    ->succeeded();
        if (!$success) {
            $view->set('validation', $validation);
            $res->setBody($view->render());
            return;
        }
        // Create account
        $user = new Model\Users([
            'username' => $username,
            'password' => $passwords[0]
        ]);
        $user->save();
        $root = Model\Inodes::New(Model\Inodes::TYPE_COLLECTION, $user->idStr(), owner: $user->id);
        $root->save();
        $this->setUserId($user->id);
        $res->redirect('/');
    }

}