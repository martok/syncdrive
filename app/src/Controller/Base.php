<?php

namespace App\Controller;

use App\Model;
use Nepf2\Application;
use Nepf2\Session\Session;
use Nepf2\Template\Template;
use Nepf2\Template\TemplateView;

class Base
{
    protected Application $app;
    protected Template $tpl;
    protected Session $session;

    public function __construct(Application $application)
    {
        $this->app = $application;
        $this->tpl = $application->tpl;
        $this->session = $application->session;
        $this->sessionInit();
    }

    private function sessionInit()
    {
        $this->session->start();
        if (isset($this->session->userid)) {
            $this->setUserId($this->session->userid);
        }
        if (!isset($this->session->showDeleted))
            $this->session->showDeleted = false;
    }

    protected function setUserId(?int $userid)
    {
        if (!is_null($userid)) {
            $user = Model\Users::findOne(["id" => $userid]);
            if ($user->id == $userid) {
                $this->session->user = $user;
                $this->session->userid = $user->id;
                // Every time we do anything authenticated, also extend the cookie lifetime
                $this->session->refreshCookie();
                return;
            }
        }
        unset($this->session->userid);
        unset($this->session->user);
    }

    public function isLoggedIn(): bool
    {
        return $this->session->isBound() && isset($this->session->userid);
    }

    protected function isSignupEnabled(): bool
    {
        return $this->app->cfg('site.registration') && !$this->app->cfg('site.readonly');
    }

    protected function isCurrentUserAdmin(): bool
    {
        return $this->isLoggedIn() && in_array($this->session->user->id, $this->app->cfg('site.adminUsers'));
    }

    /**
     * @param string $name
     * @return TemplateView
     */
    public function initTemplateView(string $name): TemplateView
    {
        $view = $this->tpl->view($name);
        $site = $this->app->cfg('site');
        $view->set('site', [
            'title' => $site['title'],
            'byline' => $site['byline'],
        ]);

        $messages = [];
        if ($this->app->cfg('site.readonly')) {
            $messages[] = 'Site is in read-only mode!';
        }
        $view->set('notifications', $messages);

        if ($this->isLoggedIn()) {
            $view->set('allow_signup', false);
            $view->set('user', [
                'id' => $this->session->user->id,
                'name' => $this->session->user->username,
                'admin' => $this->isCurrentUserAdmin(),
            ]);
        } else {
            $view->set('allow_signup', $this->isSignupEnabled());
        }
        return $view;
    }


}