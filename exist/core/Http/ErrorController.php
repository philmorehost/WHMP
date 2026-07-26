<?php

declare(strict_types=1);

namespace CodeVault\Http;

use CodeVault\Catalog\ProductGroupRepository;
use CodeVault\Container;
use CodeVault\View;

final class ErrorController
{
    public static function notFound(Container $container, Request $request): Response
    {
        $view = $container->make(View::class);
        $productGroupRepo = $container->make(ProductGroupRepository::class);

        try {
            $categories = $productGroupRepo->all();
        } catch (\Throwable) {
            $categories = [];
        }

        $content = $view->render('error.404', [
            'categories' => $categories,
            'requestPath' => $request->path(),
        ]);

        return Response::html($view->render('layouts.client', [
            'title' => '404 - Page Not Found',
            'content' => $content,
            'noindex' => true,
        ]), 404);
    }
}
