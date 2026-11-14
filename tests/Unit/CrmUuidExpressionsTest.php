<?php

test('CRM action expressions serialize record IDs instead of interpolating bare UUIDs', function () {
    $violations = [];
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/modules/crm/resources'));

    foreach ($views as $view) {
        if (! $view->isFile() || ! str_ends_with($view->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/(?:wire:(?:click|target|change|submit|init)[\w.:-]*|x-on:[\w.:-]+|@[\w.:-]+|onclick|onchange)\s*=\s*"([^"]*)"/s', file_get_contents($view->getPathname()), $attributes);

        foreach ($attributes[1] as $expression) {
            preg_match_all('/(?:\(|,)\s*\{\{\s*([^}]+)\}\}/', $expression, $parameters);

            foreach ($parameters[1] as $parameter) {
                if (str_contains($parameter, 'Js::from(')) {
                    continue;
                }

                if (preg_match('/->(?:id|\w+_id)\b|\[[\'"]id[\'"]\]|^\$id\s*$/', $parameter)) {
                    $violations[] = $view->getPathname().': '.$expression;
                }
            }
        }
    }

    expect($violations)->toBeEmpty();
});

test('CRM dialogs are resolved through the DOM instead of UUID based JavaScript identifiers', function () {
    $violations = [];
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/modules/crm/resources'));

    foreach ($views as $view) {
        if (! $view->isFile() || ! str_ends_with($view->getFilename(), '.blade.php')) {
            continue;
        }

        if (preg_match('/\b\w+\{\{[^}]+\}\}\.(?:showModal|close)\(/', file_get_contents($view->getPathname()))) {
            $violations[] = $view->getPathname();
        }
    }

    expect($violations)->toBeEmpty();
});
