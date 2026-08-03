<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Ai\AiProvider;
use CodeVault\Cache\Cache;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Seo\SeoTags;
use CodeVault\View;

/**
 * The public-facing knowledgebase (blueprint §4.1): no login required,
 * same as WHMCS's public KB — anyone can browse, search, and rate an
 * article as helpful/unhelpful.
 */
final class PublicKbController
{
    private const AI_SYSTEM_PROMPT = 'You are a support assistant. Answer the customer\'s question using ONLY '
        . 'the knowledgebase excerpts provided below. If the excerpts don\'t contain enough information to '
        . 'answer, say so plainly rather than guessing — do not invent information.';

    private const AI_MAX_ARTICLES = 3;

    public function __construct(
        private readonly View $view,
        private readonly KbArticleRepository $articles,
        private readonly KbCategoryRepository $categories,
        private readonly KbImageRepository $images,
        private readonly SeoTags $seo,
        private readonly AiProvider $ai,
        private readonly Cache $cache
    ) {
    }

    public function index(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $results = $query !== '' ? $this->articles->search($query) : null;
        $aiAnswer = null;

        if ($results !== null && $results !== []) {
            $aiAnswer = $this->synthesizeAnswer($query, $results);
        }

        $categories = $this->cache->remember('kb:index', 60, function () {
            $categories = $this->categories->all();
            $articlesByCategory = $this->articles->allGroupedByCategory();

            foreach ($categories as &$category) {
                $category['articles'] = $articlesByCategory[(int) $category['id']] ?? [];
            }
            unset($category);

            return $categories;
        });

        return $this->page('knowledgebase.public-index', [
            'categories' => $categories,
            'query' => $query,
            'results' => $results,
            'aiAnswer' => $aiAnswer,
        ], [
            'canonicalUrl' => $this->seo->canonicalUrl('/kb'),
            'metaDescription' => 'Browse help articles and guides for your hosting account.',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function synthesizeAnswer(string $query, array $results): ?string
    {
        $excerpts = [];

        foreach (array_slice($results, 0, self::AI_MAX_ARTICLES) as $article) {
            $excerpts[] = "Article: {$article['title']}\n" . mb_strimwidth(strip_tags((string) $article['body']), 0, 600, '...');
        }

        $userPrompt = "Question: {$query}\n\n" . implode("\n\n---\n\n", $excerpts);
        $result = $this->ai->complete(self::AI_SYSTEM_PROMPT, $userPrompt);

        return $result['success'] ? $result['text'] : null;
    }

    public function show(Request $request, array $params): Response
    {
        $article = $this->articles->find((int) $params['id']);

        if ($article === null) {
            return Response::html('404 Not Found', 404);
        }

        $this->articles->incrementViews((int) $article['id']);
        $article['views'] = (int) $article['views'] + 1;

        $url = $this->seo->canonicalUrl("/kb/{$article['id']}");
        $datePublished = (new \DateTimeImmutable((string) $article['created_at']))->format(DATE_ATOM);

        return $this->page('knowledgebase.public-show', [
            'article' => $article,
            'images' => $this->images->forArticle((int) $article['id']),
        ], [
            'title' => "{$article['title']} — Knowledgebase",
            'canonicalUrl' => $url,
            'metaDescription' => mb_strimwidth(strip_tags((string) $article['body']), 0, 160, '...'),
            'jsonLd' => [
                $this->seo->organization(),
                $this->seo->article($article['title'], (string) $article['body'], $datePublished, $url),
                $this->seo->breadcrumbList([
                    ['name' => 'Home', 'url' => $this->seo->canonicalUrl('/')],
                    ['name' => 'Knowledgebase', 'url' => $this->seo->canonicalUrl('/kb')],
                    ['name' => $article['title'], 'url' => $url],
                ]),
            ],
        ]);
    }

    public function rate(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $helpful = (string) $request->input('helpful', '') === '1';

        if ($helpful) {
            $this->articles->markHelpful($id);
        } else {
            $this->articles->markUnhelpful($id);
        }

        return Response::redirect("/kb/{$id}");
    }

    /** @param array<string, mixed> $seoOverrides */
    private function page(string $template, array $data, array $seoOverrides = []): Response
    {
        $content = $this->view->render($template, $data);

        return Response::html($this->view->render('layouts.client', array_merge([
            'title' => 'Knowledgebase',
            'content' => $content,
            'jsonLd' => [$this->seo->organization()],
        ], $seoOverrides)));
    }
}
