<?php
/**
 * SyncDrive
 *
 * @link       https://github.com/martok/syncdrive
 * @copyright  Copyright (c) 2023- Martok & Contributors.
 * @license    Apache License
 */

namespace App\Controller;

use App\Browser\PresentationBase;
use App\Dav\Context;
use App\Dav\FS\File;
use App\Dav\Identity;
use App\Dav\IIndexableCollection;
use App\Dav\NodeResolver;
use App\Model\Inodes;
use App\Model\InodeShares;
use App\Model\Users;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Arr;

#[Auto\RoutePrefix('/admin')]
class AdminController extends Base
{
    #[Auto\Route('/')]
    public function adminOverview(Response $res, Request $req)
    {
        if (!$this->isCurrentUserAdmin()) {
            $res->redirect('/');
            return;
        }

        $check_results = $this->checkConfig();
        $config_json = json_encode($this->app->cfg(), JSON_PRETTY_PRINT);

        $view = $this->initTemplateView('admin_overview.twig');
        $view->set('config.checks', $check_results);
        $view->set('config.merged', $config_json);
        $view->set('config.phpinfo_general', $this->getPhpInfo(INFO_GENERAL));
        $view->set('config.phpinfo_config', $this->getPhpInfo(INFO_CONFIGURATION));
        $view->set('config.phpinfo_modules', $this->getPhpInfo(INFO_MODULES));
        $res->setBody($view->render());
    }

    private function getPhpInfo(int $flags): string
    {
        ob_start();
        phpinfo($flags);
        $phpinfo = ob_get_clean();
        $body = preg_replace('%^.*<body>(.*)</body>.*$%ms', '$1', $phpinfo);
        $body = str_replace('div class="center"', 'div class="admin-phpinfo"', $body);
        $body = str_replace("<hr />\n<h1>Configuration</h1>", '', $body);
        return $body;
    }

    private function checkConfig(): array
    {
        $checks = [];
        $push = function (string $name, bool $result, string $msgFail, string $msgOk = 'Ok') use (&$checks) {
            $checks[] = [
                'name' => $name,
                'success' => $result,
                'message' => $result ? $msgOk : $msgFail,
            ];
        };

        $storageChunks = ini_parse_quantity($this->app->cfg('storage.chunkSize'));
        $memLimit = ini_parse_quantity(ini_get('memory_limit'));
        $max_execution_time = ini_get('max_execution_time');
        $max_input_time = ini_get('max_input_time');

        $push(
            'Memory Limit',
            $memLimit >= $storageChunks + ini_parse_quantity('128M'),
            "Value $memLimit < Storage Chunk Size + 128M",
        );

        $push(
            'Execution Time',
            $max_execution_time >= 900,
            "Value $max_execution_time < 900s",
        );

        $push(
            'Input Time',
            $max_input_time >= 900,
            "Value $max_input_time < 900s",
        );

        $push(
            'Storage Backends',
            count($this->app->cfg('storage.backends')),
            "No backends defined",
        );

        return $checks;
    }

    #[Auto\Route('/storage')]
    public function adminStorage(Response $res, Request $req)
    {
        if (!$this->isCurrentUserAdmin()) {
            $res->redirect('/');
            return;
        }

        $identity = Identity::System();
        $context = new Context($this->app, $identity);
        $context->setupStorage();

        $backends = [];
        foreach ($context->storage->getBackends() as $bd) {
            $backends[] = [
                'class' => get_class($bd->backend),
                'intents' => $bd->intentToStr(),
            ];
        }

        $view = $this->initTemplateView('admin_storage.twig');
        $view->set('backends', $backends);
        $res->setBody($view->render());
    }

    #[Auto\Route('/ajax/storage/usage')]
    public function apiStorageUsage(Response $res, Request $req)
    {
        if (!$this->isCurrentUserAdmin()) {
            $res->standardResponse(403);
            return;
        }

        $params = $req->jsonBody();
        $idx = $params['idx'];
        $cls = $params['class'];

        if (!is_integer($idx) || !is_string($cls)) {
            $res->standardResponse(400);
            return;
        }

        $identity = Identity::System();
        $context = new Context($this->app, $identity);
        $context->setupStorage();

        $defs = $context->storage->getBackends();
        if (($idx >= count($defs)) ||
            !($bd = $defs[$idx]) ||
            (get_class($bd->backend) !== $cls)) {
            $res->standardResponse(400);
            return;
        }

        $used = -1;
        $avail = -1;
        if (!$bd->backend->estimateCapacity($used, $avail)) {
            $res->setJSON([
                'error' => 'BackendError',
                'message' => 'Failed to calculate capacity',
            ]);
            return;
        }

        $res->setJSON([
            'used' => $used,
            'avail' => $avail
        ]);
    }
}
