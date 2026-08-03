<?php

declare(strict_types=1);

namespace CodeVault\Ai;

use CodeVault\Auth\AuthGuard;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\Support\MarkdownToText;
use CodeVault\View;

/**
 * Admin "Ask AI" helper (blueprint §5 "AI assistant everywhere") — a
 * general-purpose question box for staff, backed by the same AiProvider
 * every other AI feature uses. No app context is injected automatically;
 * it's a plain assistant, not a tool-using agent.
 */
final class AskAiController
{
    // Staff paste these answers straight into client emails, campaign bodies
    // and ticket replies — none of which render Markdown — so asking for plain
    // prose keeps "###" and "**" out of what the customer eventually reads.
    // MarkdownToText cleans up anyway when the model reverts to habit.
    private const SYSTEM_PROMPT = 'You are a helpful assistant for the staff of a web hosting and domain '
        . 'company\'s billing/support platform. Answer clearly and concisely. '
        . 'Write in plain text prose only. Do not use Markdown or any markup: no #, *, _, backticks, '
        . 'or --- separators, and no bold or italic syntax. For lists, start each line with "- ". '
        . 'Write headings as an ordinary line of text. Your answer is often pasted directly into an '
        . 'email to a customer, so it must read correctly with no formatting characters visible.';

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly View $view,
        private readonly AiProvider $ai
    ) {
    }

    public function index(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        return $this->render(['question' => '', 'answer' => null, 'error' => null]);
    }

    public function ask(Request $request): Response
    {
        if ($denied = $this->requirePermission()) {
            return $denied;
        }

        $question = trim((string) $request->input('question', ''));

        if ($question === '') {
            return $this->render(['question' => '', 'answer' => null, 'error' => null]);
        }

        $result = $this->ai->complete(self::SYSTEM_PROMPT, $question);

        return $this->render([
            'question' => $question,
            // Second line of defence: strip any Markdown the model produced in
            // spite of the system prompt, so what's on screen is exactly what
            // can be pasted into an email.
            'answer' => $result['success'] ? MarkdownToText::convert((string) $result['text']) : null,
            'error' => $result['success'] ? null : $result['error'],
        ]);
    }

    private function requirePermission(): ?Response
    {
        if (!$this->guard->check()) {
            return Response::redirect('/login');
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): Response
    {
        $content = $this->view->render('ai.ask-ai', $data);

        return Response::html($this->view->render('layouts.admin', [
            'title' => 'CodeVault Admin — Ask AI',
            'content' => $content,
        ]));
    }
}
