<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Clients\ClientRepository;
use CodeVault\Request;
use CodeVault\Response;

final class LanguageSwitchController
{
    public function __construct(
        private readonly LanguageRepository $languages,
        private readonly LanguageSelection $selection,
        private readonly ClientAuthGuard $guard,
        private readonly ClientRepository $clients
    ) {
    }

    public function select(Request $request): Response
    {
        $languageId = (int) $request->input('language_id', 0);
        $language = $this->languages->find($languageId);

        if ($language !== null && (int) $language['is_active'] === 1) {
            $this->selection->set($languageId);

            $client = $this->guard->currentClient();

            if ($client !== null) {
                $this->clients->updateLanguage((int) $client['id'], $languageId);
            }
        }

        $redirectTo = (string) $request->input('redirect', '/store');

        return Response::redirect(str_starts_with($redirectTo, '/') ? $redirectTo : '/store');
    }
}
