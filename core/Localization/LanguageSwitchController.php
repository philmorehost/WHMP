<?php

declare(strict_types=1);

namespace CodeVault\Localization;

use CodeVault\Clients\ClientAuthGuard;
use CodeVault\Security\LocalRedirect;
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

        // A user-supplied target must stay a LOCAL path: '//evil.com' (and the
        // backslash twin) is scheme-relative to browsers and used to slip past
        // the old starts-with-'/' check — an open redirect. Same rule the
        // currency switcher applies, via the one shared validator.
        return Response::redirect(LocalRedirect::sanitize($redirectTo, '/store'));
    }
}
