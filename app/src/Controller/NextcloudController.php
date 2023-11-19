<?php

namespace App\Controller;

use App\Dav\Backend\ServerAdapter;
use App\Dav\Context;
use App\Dav\FS\Directory;
use App\Dav\FS\File;
use App\Dav\FS\VirtualFilePart;
use App\Dav\FS\VirtualRoot;
use App\Dav\Identity;
use App\Model\AppPasswords;
use App\Model\ChunkedUploads;
use App\Model\LoginTokens;
use Nepf2\Auto;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\Path;
use Nepf2\Util\Random;
use Sabre\DAV\Exception;
use Sabre\DAV\Tree;
use Sabre\Uri;

class NextcloudController extends Base
{
    const IDENT_NC = ['NextCloud', '99.0.0'];
    const IDENT_OC = ['ownCloud', '10.11.0'];

    private function wrapOCS(array $data): array
    {
        return ['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => $data,
        ]];
    }

    #[Auto\Route('/index.php/204', method:['GET'])]
    public function gen204(Response $res, Request $req)
    {
        // see https://github.com/nextcloud/server/issues/6967
        $res->setStatus(204);
    }

    #[Auto\Route('/status.php', method:['GET'])]
    public function status(Response $res, Request $req)
    {
        $ident = stristr($req->getHeader('HTTP_USER_AGENT')??'', 'owncloud') ? self::IDENT_OC : self::IDENT_NC;
        $res->setJSON([
            'installed'       => true,
            'maintenance'     => false,
            'needsDbUpgrade'  => false,
            'version'         => $ident[1],
            'versionstring'   => $ident[1],
            'edition'         => '',
            'productname'     => $ident[0],
            'extendedSupport' => false,
        ]);
    }

    #[Auto\Route('/ocs/v1.php/cloud/capabilities', method: 'GET')]
    #[Auto\Route('/ocs/v2.php/cloud/capabilities', method: 'GET')]
    public function capabilities(Response $res, Request $req)
    {
        $v = explode('.', self::IDENT_NC[1]);
        $checksums = $this->app->cfg('storage.checksums');

        $res->setJSON($this->wrapOCS([
            'version' => [
                'major' => (int)$v[0],
                'minor' => (int)$v[1],
                'micro' => (int)$v[1],
                'string' => self::IDENT_NC[1],
                'edition' => '',
                'extendedSupport' => false,
            ],
            'capabilities' => [
                'core' => [
                    'webdav-root' => DavController::WEBDAV_ROOT,
                    'pollinterval' => 60,
                    'bruteforce' => ['delay' => 0],
                ],
                'dav' => [
                    // NG chunking: https://github.com/cernbox/smashbox/blob/master/protocol/chunking.md
                    // "1.0" means "NG" actually... lol: https://github.com/owncloud/client/issues/7862#issuecomment-717953394
                    'chunking' => '1.0',
                ],
                'files' => [
                    // old v1 chunking: https://github.com/cernbox/smashbox/blob/master/protocol/protocol.md#chunked-file-upload
                    'bigfilechunking' => true,
                    'comments' => false,
                    'undelete' => false,
                    'versioning' => false,
                ],
                'files_sharing' => [
                    'api_enabled' => false,
                    'group_sharing' => false,
                    'resharing' => false,
                    'sharebymail' => ['enabled' => false],
                ],
                'user' => [
                    'expire_date' => ['enabled' => false],
                    'send_mail' => false,
                ],
                'public' => [
                    'enabled' => false,
                    'expire_date' => ['enabled' => false],
                    'multiple_links' => false,
                    'send_mail' => false,
                    'upload' => false,
                    'upload_files_drop' => false,
                ],
                'checksums' => [
                    'supportedTypes' => $checksums,
                    'preferredUploadType' => count($checksums) ? $checksums[0] : '',
                ],
            ],
        ]));
    }

    #[Auto\Route('/index.php/login/flow', method:['GET'])]
    public function login_v1(Response $res, Request $req)
    {
        if (!$req->getHeader('OCS-APIREQUEST')) {
            $res->standardResponse(400);
            return;
        }

        LoginTokens::Expire();
        list($loginBase) = Path::Pop($req->getAbsoluteUrl());
        $token = LoginTokens::New($req->getHeader('User-Agent') ?? 'Unknown App', version: 1);
        // redirect into login v2 flow, which will recognize v1 by the versioned poll_token
        $res->redirect(implode('/', [$loginBase, 'v2', 'flow', $token->login_token]));
    }

    #[Auto\Route('/index.php/login/v2', method:['POST'])]
    public function login_v2(Response $res, Request $req)
    {
        LoginTokens::Expire();
        list($loginBase) = Path::Pop($req->getAbsoluteUrl());
        $token = LoginTokens::New($req->getHeader('User-Agent') ?? 'Unknown App');
        $res->setJSON([
            'poll' => [
                'token' => $token->poll_token,
                'endpoint' => implode('/', [$loginBase , 'poll']),
            ],
            'login' => implode('/', [$loginBase, 'v2', 'flow', $token->login_token]),
        ]);
    }

    #[Auto\Route('/index.php/login/v2/flow/<loginToken>', method:['GET', 'POST'])]
    public function login_v2_flow(Response $res, Request $req, string $loginToken)
    {
        LoginTokens::Expire();
        $token = LoginTokens::findOne(['login_token' => $loginToken, 'login_name'=> null]);
        // if this token doesn't exist or is already authenticated, skip
        if (is_null($token->id)) {
            $res->standardResponse(404);
            return;
        }

        // if there is no user currently logged in, note this token, redirect to login and then come back
        if (!$this->isLoggedIn()) {
            $this->session->login_flow_redirect = $req->getAbsoluteUrl();
            $res->redirect('/user/login');
            return;
        }
        unset($this->session->login_flow_redirect);

        // doesn't have to be secure, just reasonably unguessable to anyone who doesn't know / is the user agent to
        // prevent all too simple replay attacks.
        $responseMac = hash('sha256', implode('', [$loginToken, $token->user_agent]));

        // was the question answered?
        if ($req->getMethod() === 'POST') {
            if ($req->str('response') === $responseMac) {
                // any answer at all invalidates the current login token (but not the poll token, we still need that)
                $token->login_token = Random::TokenStr(LoginTokens::TOKEN_LEN_LOGIN);
                $token->save();

                if ($req->bool('confirm')) {
                    $user = $this->session->user;
                    $ap = AppPasswords::New($token->user_agent, $user, $generatedPassword);

                    if ($token->isV1Token()) {
                        // V1 flow (android)
                        $token->delete();
                        $serverUrl = $req->getAbsoluteUrl();
                        $serverUrl = substr($serverUrl, 0, strpos($serverUrl, '/index.php/login/'));
                        $credURL = sprintf('nc://login/server:%s&user:%s&password:%s',
                                           $serverUrl,
                                           urlencode($ap->login_name),
                                           urlencode($generatedPassword));
                        $res->redirect($credURL);
                        return;
                    } else {
                        // V2 flow (desktop)
                        $token->login_name = $ap->login_name;
                        $token->app_password = $generatedPassword;
                        $token->save();
                    }
                }
                $res->redirect('/');
                return;
            }
        }

        $view = $this->initTemplateView('user_login_flow.twig');
        $view->set('flowUri', $req->getAbsoluteUrl());
        $view->set('response', $responseMac);
        $view->set('userAgent', $token->user_agent);
        $res->setBody($view->render());
    }

    #[Auto\Route('/index.php/login/v2/poll', method:['POST'])]
    public function login_v2_poll(Response $res, Request $req)
    {
        LoginTokens::Expire();
        $pollToken = $req->str('token');
        $token = LoginTokens::findOne(['poll_token' => $pollToken, 'login_name-'=> null]);
        if (!is_null($token->id)) {
            $res->setJSON([
                'server' => $req->getAbsoluteBase(),
                'loginName' => $token->login_name,
                'appPassword' => $token->app_password,
            ]);
            $token->delete();
            return;
        }
        $res->standardResponse(404);
        $res->setJSON([]);
    }

    #[Auto\Route('/ocs/v1.php/cloud/user', method:['GET'])]
    #[Auto\Route('/ocs/v2.php/cloud/user', method:['GET'])]
    public function userMeta(Response $res, Request $req)
    {
        $res->setJSON([]);
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initApp($req, $res)) {
            $res->setStatus(403);
            return;
        }
        $userid = $identity->user->id;
        if (!is_null($identity->appLogin) &&
            !is_null($req->getHeader('OCS-APIREQUEST'))) {
            // The android client erroneously uses the value in 'id' to build DAV urls instead
            // of the login name obtained from login flow.
            // Nothing else uses the ID anyway, so we can just lie to it here.
            $userid = $identity->appLogin;
        }
        $res->setJSON($this->wrapOCS([
            'id' => $userid,
            'display-name' => $identity->user->username,
            'email' => $identity->user->username,
        ]));
    }

    private function checkTransferId(string $transfer): bool
    {
        return 1 === preg_match('#^[0-9a-z]{3,255}$#i', $transfer);
    }

    private function checkPartNo(string $part, ?string $chunkOffsetHeader): array
    {
        // standard form: numeric ident and header
        if (is_numeric($part) && is_numeric($chunkOffsetHeader)) {
            return [($part), (int)$chunkOffsetHeader];
        }
        // Android clients >= 3.26: no header, and numeric ident
        if (is_numeric($part) && empty($chunkOffsetHeader)) {
            return [($part), null];
        }
        // Android clients < 3.26: no header, and ident as <start>-<end>
        if (preg_match('#^(\d+)-(\d+)$#', $part, $matches) && empty($chunkOffsetHeader))
            return [(int)$matches[1], (int)$matches[1]];
        return [null, null];
    }

    #[Auto\Route(DavController::PREFIX_CHUNKING_V2 . '<login>/<transfer>', method: 'MKCOL')]
    public function chunkingInitiate(Response $res, Request $req, string $login, string $transfer)
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initApp($req, $res, $login)) {
            $res->setStatus(403);
            return;
        }
        $context = new Context($this->app, $identity);
        $server = new ServerAdapter(new Tree(new VirtualRoot($context)), $req, $res);
        try {
            if (!$this->checkTransferId($transfer)) {
                throw new Exception\BadRequest('Invalid transfer id');
            }
            if ($totalLength = $req->getHeader('OC-Total-Length')) {
                if (!is_numeric($totalLength)) {
                    throw new Exception\BadRequest('Bad final file size');
                }
            } else {
                // Mobile clients don't send OC-Total-Length
                $totalLength = -1;
            }

            ChunkedUploads::db()->beginTransaction();
            if (!is_null(ChunkedUploads::Find($transfer))) {
                throw new Exception\BadRequest('Duplicate transfer id');
            }
            $upload = ChunkedUploads::FindOrStartTransfer($transfer, totalLength: $totalLength);
            ChunkedUploads::db()->commit();
            $res->setStatus(201);
        } catch (Exception $e) {
            $server->sendException($e);
            return false;
        }
    }

    #[Auto\Route(DavController::PREFIX_CHUNKING_V2 . '<login>/<transfer>/<partIdent>', method: 'PUT')]
    public function chunkingPut(Response $res, Request $req, string $login, string $transfer, string $partIdent)
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initApp($req, $res, $login)) {
            $res->setStatus(403);
            return;
        }
        $context = new Context($this->app, $identity);
        $server = new ServerAdapter(new Tree(new VirtualRoot($context)), $req, $res);
        try {
            $upload = ChunkedUploads::Find($transfer);
            if (is_null($upload)) {
                throw new Exception\BadRequest('Transfer not created');
            }
            list($part, $offset) = $this->checkPartNo($partIdent, $req->getHeader('OC-Chunk-Offset'));
            if (is_null($part)) {
                throw new Exception\BadRequest('Invalid chunk number');
            }
            // if we have an offset and got a length ahead of time, check it
            if (!is_null($offset) &&
                ($upload->total_length >= 0) && (
                !is_numeric($length = $req->getHeader('Content-Length')) ||
                ((int)$offset + (int)$length > $upload->total_length))
                ) {
                throw new Exception\BadRequest('Invalid chunk size');
            }

            $context->setupStorage();
            $object = $context->storeUploadedData($req->getBodyAsStream());
            ChunkedUploads::db()->beginTransaction();
            $upload->saveChunk($partIdent, $object, $context->storage);
            ChunkedUploads::db()->commit();
            $res->setStatus(201);
        } catch (Exception $e) {
            $server->sendException($e);
            return false;
        }
    }

    #[Auto\Route(DavController::PREFIX_CHUNKING_V2 . '<login>/<transfer>', method: 'PROPFIND')]
    public function chunkingEnumerate(Response $res, Request $req, string $login, string $transfer)
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initApp($req, $res, $login)) {
            $res->setStatus(403);
            return;
        }
        $context = new Context($this->app, $identity);
        $root = new VirtualRoot($context);
        $server = new ServerAdapter(new Tree($root), $req, $res);
        try {
            $server->setBaseUri(TreeUtil::requestBaseUri($req, []));
            $upload = ChunkedUploads::Find($transfer);
            if (is_null($upload)) {
                throw new Exception\BadRequest('Transfer not created');
            }
            foreach ($upload->findParts() as $part) {
                $root->addChild(new VirtualFilePart($part));
            }
            $server->start();
        } catch (Exception $e) {
            $server->sendException($e);
        }
        return false;
    }

    #[Auto\Route(DavController::PREFIX_CHUNKING_V2 . '<login>/<transfer>', method: 'DELETE')]
    public function chunkingCancel(Response $res, Request $req, string $login, string $transfer)
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initApp($req, $res, $login)) {
            $res->setStatus(403);
            return;
        }
        $context = new Context($this->app, $identity);
        $server = new ServerAdapter(new Tree(new VirtualRoot($context)), $req, $res);
        try {
            $upload = ChunkedUploads::Find($transfer);
            if (is_null($upload)) {
                throw new Exception\BadRequest('Transfer not created');
            }
            $context->setupStorage();
            ChunkedUploads::db()->beginTransaction();
            $upload->deleteWithObjects($context->storage);
            ChunkedUploads::db()->commit();
        } catch (Exception $e) {
            $server->sendException($e);
            return false;
        }
    }

    #[Auto\Route(DavController::PREFIX_CHUNKING_V2 . '<login>/<transfer>/.file', method: 'MOVE')]
    public function chunkingFinalize(Response $res, Request $req, string $login, string $transfer)
    {
        $identity = new Identity($this->app->cfg('site.title'));
        if (!$identity->initApp($req, $res, $login)) {
            $res->setStatus(403);
            return;
        }
        $context = new Context($this->app, $identity);
        $server = new ServerAdapter($context->getFilesView(), $req, $res);
        try {
            // set the base Uri for the destination
            $server->setBaseUri(DavController::MakeUserPath($login, ''));
            $upload = ChunkedUploads::Find($transfer);
            if (is_null($upload)) {
                throw new Exception\BadRequest('Transfer not created');
            }
            $totalLength = array_sum($upload->findParts()->toArray(['column' => 'size']));
            // if the total length was given either before or in this request, check it
            if ((($upload->total_length >= 0) && ($totalLength != $upload->total_length)) ||
                (is_numeric($req->getHeader('OC-Total-Length')) &&
                 ($totalLength != (int)$req->getHeader('OC-Total-Length')))) {
                throw new Exception\BadRequest('Upload is not complete');
            }

            // locate the move target
            $moveInfo = $server->getCopyAndMoveInfo($req);
            if ($moveInfo['destinationNode'] && $req->getHeader('If')) {
                // this should only take the 'getIfConditions' branch, therefore
                // the 'invalid' path is irrelevant
                $server->checkPreconditions($req, $res);
            }

            $destination = $server->calculateUri($req->getHeader('Destination'));
            list($destinationDir) = Uri\split($destination);
            $destinationParent = $server->tree->getNodeForPath($destinationDir);
            assert($destinationParent instanceof Directory);

            $context->setupStorage();
            $etag = $destinationParent->moveChunkedToFile($upload, $moveInfo['destinationNode'] ?: null, $moveInfo['destination']);
            if (!$etag) {
                throw new Exception\Conflict('Failed to rename file');
            }
            File::AddUploadHeaders($destinationParent->getChild($moveInfo['destination']));
            $res->setHeader('Content-Length', '0');
            $res->setHeader('OC-Etag', $etag);
            $res->setStatus($moveInfo['destinationExists'] ? 204 : 201);
        } catch (Exception $e) {
            $server->sendException($e);
            return false;
        }
    }
}
