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
                $this->session->resetCookie();
                return;
            }
        }
        unset($this->session->userid);
        unset($this->session->user);
    }

    public function isLoggedIn(): bool
    {
        return $this->session->started() && isset($this->session->userid);
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
            'owner' => $site['owner'],
        ]);

        $messages = [];
        if ($this->app->cfg('site.readonly')) {
            $messages[] = 'Site is in read-only mode!';
        }
        $view->set('notifications', $messages);

        if ($this->isLoggedIn()) {
            $view->set('user', [
                'id' => $this->session->user->id,
                'name' => $this->session->user->username,
            ]);
        }
        return $view;
    }


}