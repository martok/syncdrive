<?php

namespace App\Controller;

use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;

class IndexController extends Base
{
	#[Auto\Route('/')]
	public function index(Response $res, Request $req)
	{
        if ($this->isLoggedIn()) {
            $res->redirect('/browse');
        } else {
            $view = $this->initTemplateView('index_extern.twig');
            $res->setBody($view->render());
        }
    }

	#[Auto\Route('/<x*>', priority:-1000, slash:true)]
	public function notFound(Response $res, Request $req, array $x)
	{
		$res->setStatus(404);
		$res->setBody("No handler found for {$req->getPath()}");
	}
}
