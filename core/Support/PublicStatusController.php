<?php

declare(strict_types=1);

namespace CodeVault\Support;

use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

/**
 * Public status page (blueprint §4.1): active incidents, recent
 * resolution history, and published announcements — no login required,
 * same as WHMCS's Network Issues + Announcements client-area widgets
 * combined into one page.
 */
final class PublicStatusController
{
    public function __construct(
        private readonly View $view,
        private readonly NetworkIssueRepository $issues,
        private readonly AnnouncementRepository $announcements,
        private readonly SeoTags $seo
    ) {
    }

    public function index(Request $request): Response
    {
        $content = $this->view->render('support.public-status', [
            'activeIssues' => $this->issues->active(),
            'resolvedIssues' => $this->issues->recentlyResolved(),
            'announcements' => $this->announcements->published(),
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'System Status',
            'content' => $content,
            'canonicalUrl' => $this->seo->canonicalUrl('/status'),
            'metaDescription' => 'Current system status, network issues, and announcements.',
            'jsonLd' => [$this->seo->organization()],
        ]));
    }
}
