<?php

declare(strict_types=1);

namespace CodeVault\Knowledgebase;

use CodeVault\Ai\AiProvider;
use CodeVault\Ai\AiSettings;
use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Settings\SettingsRepository;
use CodeVault\Staff\PermissionRegistry;

/**
 * "Write with AI" for the knowledgebase: articles, categories, and simple
 * explanatory diagrams.
 *
 * Article/category generation follows CampaignCopilotController's shape
 * exactly (JSON in, fills form fields, nothing is saved until the admin
 * submits the real form). Diagram generation is different on purpose: there
 * is no image-generation provider in this codebase (DeepSeekProvider is
 * text-only), so "AI image" here means the model writes actual <svg> markup,
 * which KbSvgSanitizer reduces to a safe drawing-primitive subset before it
 * is ever stored or served. A diagram can't be "previewed then discarded"
 * the way text can — it needs to exist as a row to be served as an <img src>
 * at all — so generateImage() saves directly and redirects back to the
 * article, rather than returning JSON for a form field to hold.
 */
final class KbCopilotController
{
    private const FEATURE = 'kb_copilot';

    private const SYSTEM_PROMPT_ARTICLE =
        "You write knowledgebase support articles for a web hosting and domain company, for its own customers to "
        . "self-serve solve a problem or learn a feature. Return exactly this structure and nothing else:\n"
        . "TITLE: <a short, clear title, under 100 characters, no quotes>\n"
        . "---BODY---\n"
        . "<the article body as plain text>\n\n"
        . "Rules for the body: plain text only — no HTML, no Markdown symbols (no #, *, -, no code fences). "
        . "Separate distinct points or steps into their own paragraphs with a blank line between them; that blank "
        . "line is what becomes a paragraph break when this is displayed. Write steps as plain sentences "
        . "(\"First, ...\", \"Next, ...\") rather than a Markdown list. Be concrete and specific — do not invent "
        . "prices, dates, or feature names that were not given to you.\n\n"
        . "You have never seen this company's actual product — you don't know its real page names, button "
        . "labels, menu paths, or exact click-by-click steps, and guessing at them produces support articles "
        . "that mislead the very customers reading them. If a \"Reference material\" section is given below, "
        . "it describes the real screens/buttons/paths for this topic — every step, button label, page name, "
        . "and click-path in the article must come from that material, in the order given; do not invent, "
        . "rename, reorder, or add steps it doesn't mention. If no reference material is given, do not state a "
        . "specific button label, menu path, or click-by-click sequence at all — describe the task and general "
        . "concept in plain terms, and add one clear sentence noting that a staff member should confirm the "
        . "exact click path before publishing.";

    private const SYSTEM_PROMPT_CATEGORY =
        "You name and describe a knowledgebase category for a web hosting and domain company's support site. "
        . "Return exactly this structure and nothing else:\n"
        . "NAME: <a short category name, under 60 characters, Title Case, no quotes>\n"
        . "DESCRIPTION: <one or two plain-text sentences describing what belongs in this category, under 200 characters>";

    private const SYSTEM_PROMPT_SVG =
        "You create simple, clean explanatory diagrams for a knowledgebase support article, as SVG markup. "
        . "Return ONLY a single <svg>...</svg> element and nothing else — no Markdown code fence, no explanation "
        . "before or after it.\n"
        . "Requirements: viewBox=\"0 0 800 400\" (or a similar wide aspect ratio). Use only these elements: "
        . "svg, g, path, rect, circle, ellipse, line, polyline, polygon, text, tspan, defs, marker, "
        . "linearGradient, radialGradient, stop, title, desc. Set colors with fill=\"...\" and stroke=\"...\" "
        . "attributes directly on elements (no style attribute, no <style> block, no CSS classes). No external "
        . "images, no <script>, no event handler attributes, no <foreignObject>, no <a>, no <use>, no links or "
        . "hrefs of any kind. Keep any text short and legible (font-size 14 or larger). Use a white background "
        . "rectangle behind the drawing so it reads clearly regardless of the surrounding page theme.";

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly AiProvider $ai,
        private readonly AiSettings $aiSettings,
        private readonly SettingsRepository $settings,
        private readonly KbArticleRepository $articles,
        private readonly KbImageRepository $images
    ) {
    }

    public function generateArticle(Request $request): Response
    {
        if ($denied = $this->guardApi()) {
            return $denied;
        }

        $mode = (string) $request->input('mode', 'write') === 'refine' ? 'refine' : 'write';
        $brief = trim((string) $request->input('brief', ''));
        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));
        // Real screen/button/menu facts about the topic, pasted in by the
        // admin — the seam that keeps the copilot from inventing UI details
        // it has no way of actually knowing. See SYSTEM_PROMPT_ARTICLE.
        $referenceNotes = trim((string) $request->input('reference_notes', ''));

        if ($mode === 'refine' && $title === '' && $body === '') {
            return Response::json(['success' => false, 'message' => 'Write a title or body first, then Refine it.']);
        }

        if ($mode === 'write' && $brief === '') {
            return Response::json(['success' => false, 'message' => 'Describe what the article should cover.']);
        }

        $company = $this->companyName();
        $referenceBlock = $referenceNotes !== ''
            ? "\n\nReference material (the real steps/screens/buttons for this topic — ground every step in this, "
                . "do not invent beyond it):\n{$referenceNotes}"
            : '';
        $prompt = $mode === 'refine'
            ? "Company: {$company}\n\n"
                . "Improve the article below. Keep its meaning and any specific facts, steps or settings exactly "
                . "as they are — tighten the wording, fix grammar, and make it clearer.\n\n"
                . ($brief !== '' ? "Extra instructions: {$brief}\n\n" : '')
                . "Current title: {$title}\n\nCurrent body:\n{$body}"
                . $referenceBlock
            : "Company: {$company}\n\nWrite an article about the following:\n{$brief}"
                . ($title !== '' ? "\n\nThe admin has drafted this title, improve on it: {$title}" : '')
                . $referenceBlock;

        $result = $this->ai->complete(self::SYSTEM_PROMPT_ARTICLE, $prompt);

        if (($result['success'] ?? false) !== true) {
            return Response::json(['success' => false, 'message' => (string) ($result['error'] ?? 'The AI service did not respond.')]);
        }

        $parsed = self::parseTitleBody((string) $result['text']);

        if ($parsed['body'] === '') {
            return Response::json(['success' => false, 'message' => 'The AI returned an empty draft. Try rephrasing the brief.']);
        }

        return Response::json(['success' => true, 'title' => $parsed['title'], 'body' => $parsed['body'], 'message' => null]);
    }

    public function generateCategory(Request $request): Response
    {
        if ($denied = $this->guardApi()) {
            return $denied;
        }

        $brief = trim((string) $request->input('brief', ''));

        if ($brief === '') {
            return Response::json(['success' => false, 'message' => 'Describe what this category is for.']);
        }

        $result = $this->ai->complete(self::SYSTEM_PROMPT_CATEGORY, "Company: {$this->companyName()}\n\nCategory brief: {$brief}");

        if (($result['success'] ?? false) !== true) {
            return Response::json(['success' => false, 'message' => (string) ($result['error'] ?? 'The AI service did not respond.')]);
        }

        $parsed = self::parseNameDescription((string) $result['text']);

        if ($parsed['name'] === '') {
            return Response::json(['success' => false, 'message' => 'The AI returned an empty draft. Try rephrasing the brief.']);
        }

        return Response::json(['success' => true, 'name' => $parsed['name'], 'description' => $parsed['description'], 'message' => null]);
    }

    /**
     * Generates a diagram and saves it directly to the article — see class
     * docblock for why this doesn't return JSON like the two methods above.
     */
    public function generateImage(Request $request, array $params): Response
    {
        $articleId = (int) $params['id'];

        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        if (!$this->guard->can(PermissionRegistry::KB_MANAGE)) {
            return Response::html('403 Forbidden — missing kb.manage permission', 403);
        }

        $article = $this->articles->find($articleId);

        if ($article === null) {
            return Response::html('404 Not Found', 404);
        }

        $editUrl = "/admin/kb/articles/{$articleId}/edit";

        if (!$this->aiSettings->isFeatureEnabled(self::FEATURE)) {
            return Response::redirect($editUrl . '?img_error=' . urlencode('The KB copilot is switched off under Configuration → AI.'));
        }

        if (!$this->aiSettings->hasKey()) {
            return Response::redirect($editUrl . '?img_error=' . urlencode('No AI API key is configured. Add one under Configuration → AI.'));
        }

        $prompt = trim((string) $request->input('prompt', ''));

        if ($prompt === '') {
            return Response::redirect($editUrl . '?img_error=' . urlencode('Describe the diagram you want generated.'));
        }

        $userPrompt = "Article title: {$article['title']}\n\nDiagram to draw: {$prompt}";
        $result = $this->ai->complete(self::SYSTEM_PROMPT_SVG, $userPrompt);

        if (($result['success'] ?? false) !== true) {
            return Response::redirect($editUrl . '?img_error=' . urlencode((string) ($result['error'] ?? 'The AI service did not respond.')));
        }

        $svg = KbSvgSanitizer::sanitize((string) $result['text']);

        if ($svg === null) {
            return Response::redirect($editUrl . '?img_error=' . urlencode('The AI did not return a usable diagram. Try a simpler or more specific description.'));
        }

        $this->images->create([
            'article_id' => $articleId,
            'source' => 'ai_generated',
            'svg_content' => $svg,
            'mime_type' => 'image/svg+xml',
            'size_bytes' => strlen($svg),
            'caption' => mb_substr($prompt, 0, 255),
        ]);

        return Response::redirect($editUrl . '?img_uploaded=1');
    }

    private function guardApi(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::json(['success' => false, 'message' => 'Please log in.'], 401);
        }

        if (!$this->guard->can(PermissionRegistry::KB_MANAGE)) {
            return Response::json(['success' => false, 'message' => 'You do not have permission to use this.'], 403);
        }

        if (!$this->aiSettings->isFeatureEnabled(self::FEATURE)) {
            return Response::json(['success' => false, 'message' => 'The KB copilot is switched off under Configuration → AI.']);
        }

        if (!$this->aiSettings->hasKey()) {
            return Response::json(['success' => false, 'message' => 'No AI API key is configured. Add one under Configuration → AI.']);
        }

        return null;
    }

    private function companyName(): string
    {
        return trim((string) ($this->settings->get('theme.brand_name', '') ?? '')) ?: 'our company';
    }

    /** @return array{title: string, body: string} */
    private static function parseTitleBody(string $raw): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        $text = preg_replace('/^```[a-zA-Z]*\n?|\n?```$/', '', $text) ?? $text;

        $title = '';

        if (preg_match('/^\s*TITLE:\s*(.+)$/mi', $text, $m) === 1) {
            $title = trim(strip_tags($m[1]), " \t\"'");
            $text = (string) preg_replace('/^\s*TITLE:\s*.+$/mi', '', $text, 1);
        }

        $parts = preg_split('/^\s*-{2,}\s*BODY\s*-{2,}\s*$/mi', $text, 2);
        $body = trim(strip_tags(trim($parts[1] ?? $parts[0] ?? '')));

        return ['title' => mb_substr($title, 0, 255), 'body' => $body];
    }

    /** @return array{name: string, description: string} */
    private static function parseNameDescription(string $raw): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $raw));

        $extract = static function (string $label, string $text): string {
            if (preg_match('/^\s*' . $label . ':\s*(.+)$/mi', $text, $m) === 1) {
                return trim(strip_tags($m[1]), " \t\"'");
            }

            return '';
        };

        return [
            'name' => mb_substr($extract('NAME', $text), 0, 191),
            'description' => mb_substr($extract('DESCRIPTION', $text), 0, 500),
        ];
    }
}
