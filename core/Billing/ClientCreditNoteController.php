<?php

declare(strict_types=1);

namespace CodeVault\Billing;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Pdf\CreditNotePdfBuilder;
use CodeVault\Request;
use CodeVault\Response;
use CodeVault\View;

/** Client-side Credit Notes — view own, download PDF (R18). */
final class ClientCreditNoteController
{
    public function __construct(
        private readonly ClientAuthGuard $guard,
        private readonly View $view,
        private readonly CreditNoteRepository $creditNotes,
        private readonly CreditNotePdfBuilder $pdf
    ) {
    }

    public function index(Request $request): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $content = $this->view->render('billing.client-credit-notes-index', [
            'creditNotes' => $this->creditNotes->forClient((int) $client['id']),
        ]);

        return Response::html($this->view->render('layouts.client', [
            'title' => 'CodeVault — My Credit Notes',
            'content' => $content,
        ]));
    }

    public function downloadPdf(Request $request, array $params): Response
    {
        $client = $this->guard->currentClient();

        if ($client === null) {
            return Response::redirect('/client/login');
        }

        $creditNote = $this->creditNotes->find((int) $params['id']);

        if ($creditNote === null || (int) $creditNote['client_id'] !== (int) $client['id']) {
            return Response::html('404 Not Found', 404);
        }

        $bytes = $this->pdf->build($creditNote, $this->creditNotes->items((int) $creditNote['id']), $client);

        return (new Response($bytes, 200))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', "inline; filename=\"credit-note-CN-{$creditNote['id']}.pdf\"")
            ->withHeader('Content-Length', (string) strlen($bytes));
    }
}
