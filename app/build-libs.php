<?php

$outDir = realpath(__DIR__ . '/../public/static/lib');

function getAndMerge(string $fileName, array $sourceUrls): void
{
    $contents = [];

    foreach($sourceUrls as $sourceUrl) {
        echo "Fetching $sourceUrl...\n";
        $src = file_get_contents($sourceUrl);
        $ts = gmdate(DATE_RFC3339);
        $contents[] = "/* $sourceUrl fetched at $ts */";
        $contents[] = $src;
        $contents[] = "";
    }

    @mkdir(dirname($fileName), recursive: true);
    file_put_contents($fileName, join("\n", $contents));
    echo "Created $fileName...\n";
}

getAndMerge($outDir . '/merged.css', [
    'https://cdn.jsdelivr.net/npm/uikit@3.21/dist/css/uikit.min.css',
    'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css',
]);

getAndMerge($outDir . '/merged.js', [
    'https://cdn.jsdelivr.net/npm/uikit@3.21/dist/js/uikit.min.js',
    'https://cdn.jsdelivr.net/npm/uikit@3.21/dist/js/uikit-icons.min.js',
    'https://cdn.jsdelivr.net/npm/jquery@3.7/dist/jquery.min.js',
    'https://cdn.jsdelivr.net/npm/timeago@1.6/jquery.timeago.min.js',
    'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js',
]);