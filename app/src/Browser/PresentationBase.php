<?php

namespace App\Browser;

use App\Controller\Base;
use App\Exception;
use Nepf2\Request;
use Nepf2\Response;
use Nepf2\Util\ClassUtil;

class PresentationBase extends BrowserViewBase
{
    protected const PRESENTATION_CLASSES = [
        'index' => PresentationIndex::class,
        'rich' => PresentationRich::class,
    ];

    public static function AvailablePresentations(): array
    {
        return array_keys(self::PRESENTATION_CLASSES);
    }

    public static function CreateForPresentation(string   $name,
                                                 Base     $controller,
                                                 Request  $request,
                                                 Response $response): PresentationBase
    {
        $cls = self::PRESENTATION_CLASSES[$name] ?? '';
        if (!$cls || !ClassUtil::IsClass($cls))
            throw new Exception("Unknown presentation class: $name");
        return new $cls($controller, $request, $response);
    }

    public function updateIndexState(Request $req, array &$state): void
    {
    }
}