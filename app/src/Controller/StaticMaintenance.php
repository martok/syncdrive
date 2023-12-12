<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Controller;

use Nepf2\Application;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Template\Template;

class StaticMaintenance
{
    protected Application $app;
    protected Template $tpl;

    public function __construct(Application $application)
    {
        $this->app = $application;
        $this->tpl = $application->tpl;
    }

    #[Auto\Route('/')]
    #[Auto\Route('/<x*>', priority:-1000, slash:true)]
    public function index(Response $res, Request $req)
    {
        $view = $this->tpl->view('index_maint.twig');
        $site = $this->app->cfg('site');
        $view->set('site', [
            'title' => $site['title'],
            'byline' => $site['byline'],
        ]);
        $res->setBody($view->render());
    }

}