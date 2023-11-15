<?php

namespace App\Controller;

use App\Dav\Backend\LocksBackend;
use App\Dav\Backend\PropsBackend;
use App\Dav\Backend\ServerAdapter;
use App\Dav\FS\File;
use App\Dav\IIndexableCollection;
use Nepf2\Request;
use Sabre\DAV;

class TreeUtil
{
    public static function requestBaseUri(Request $req, array $pathSegment): string
    {
        $base = '/' . $req->getPath();
        $plainPath = implode('/', $pathSegment);
        if ($plainPath)
            $base = substr($base, 0, -strlen($plainPath));
        return '/' . ltrim($base, '/');
    }

    public static function extendPath(string $rootPath, string $file): string
    {
        return ltrim($rootPath . '/' . $file, '/');
    }

    public static function setupServer(ServerAdapter $server, string $baseUri): void
    {
        $server->setBaseUri($baseUri);
        $server->addPlugin(new DAV\Locks\Plugin(new LocksBackend($server->tree)));
        $server->addPlugin(new DAV\PropertyStorage\Plugin(new PropsBackend($server->tree)));
    }

    public static function getNodeIcon(DAV\INode $node): string
    {
        if ($node instanceof File) {
            return 'file';
        } elseif ($node instanceof IIndexableCollection) {
            return 'folder';
        }
        return 'question';
    }

    public static function getPathBreadcrumbs(array $path): array
    {
        // exploding the empty string yields an array containing the empty string, catch that here
        if (count($path) === 1 && $path[0] === '')
            return [];
        $segments = [];
        array_reduce($path, function ($start, $part) use (&$segments) {
            $next = self::extendPath($start, $part);
            $segments[] = [
                'name' => $part,
                'path' => $next
            ];
            return $next;
        }, '');
        return $segments;
    }

    public static function filterListKeys(array $list, array $keys): array
    {
        $template = array_flip($keys);
        return array_map(fn ($item) => array_intersect_key($item, $template), $list);
    }
}