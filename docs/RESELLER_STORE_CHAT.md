# A store's own support chat

## The rule

**The platform's chatbox never appears on a domain that belongs to a store.**

That is not a preference. The platform's Tawk.To widget is rendered from
`layouts.client`, which is the layout behind *every* public page — the storefront,
the knowledgebase, checkout, the client area. Without this rule, a reseller's
customer opens a chat looking for the reseller and reaches **us**, about the
reseller's prices.

So on a host that matched a store, one of these is shown, and ours is not:

| The store has | Its storefront shows |
|---|---|
| a Tawk.To property id | the store's own Tawk.To widget |
| a WhatsApp number | a WhatsApp button in the store's accent colour |
| neither | **nothing** — no chat at all |

Nothing is not a gap to be filled. A store with no chat has its contact page and
ticket form one link away, and filling that corner with our chatbox is the exact
failure this exists to prevent. The check is a pair in
`tests/Unit/ResellerChatTest.php`: the same configured platform widget renders on
the platform's own host, and does not render on a store's. Either half alone would
pass against a widget that simply never rendered anywhere.

Implementation: `resources/views/partials/live-chat.php` decides,
`core/Reseller/ResellerChat.php` owns the rules. The admin layout still includes
the platform widget directly, because the admin panel *is* the platform.

## WhatsApp is the default; Tawk.To is the upgrade

A reseller has a phone number the day they sign up and a Tawk.To property maybe
never. So the default channel — the one that works with nothing to sign up for —
is a click-to-chat WhatsApp button, pre-filled with a greeting naming the store.

When a reseller sets a Tawk.To property id it **replaces** the button rather than
joining it. That is deliberate: two chat launchers stacked in the same corner is
worse than either.

- The number is stored as digits with the country code, because `wa.me` takes
  E.164 and nothing else. Spaces, dashes, brackets, `+` and `00` are accepted when
  typed and normalised away.
- The reseller's own account phone is offered as the field's default value, so the
  button works without them retyping a number the platform already holds.
- Setting a number is optional. Blank means no chat.

## Why only the Tawk.To property ID is accepted

The id is the one part of Tawk's embed that belongs in a URL we generate. Accepting
a **pasted snippet** would put a reseller's arbitrary JavaScript on a page served
under this platform's security headers.

So `ResellerChat::normaliseTawkProperty()` accepts only an alphanumeric token and
refuses everything else — including a pasted embed block, which is what Tawk's own
instructions tell you to copy. That refusal is recognised specifically
(`looksLikePastedEmbed()`), so the error explains what to paste instead of just
saying "invalid".

The application builds the snippet itself and stamps the response's CSP nonce onto
the inline loader (`SecurityHeaders::stampInlineScripts`). Without the nonce,
`script-src` blocks it and the store has a widget that silently never loads.

What to paste:

```
https://embed.tawk.to/6a1b2c3d4e5f6a7b8c9d0e1f/default
                        ^ property id          ^ widget id (optional)
```

## Where it is set

- The reseller: **Reseller area → Your store → Your support chat**.
- An admin, on their behalf: **/admin/resellers/{clientId}/store → Support chat**.
  Mostly for support — a reseller who cannot work the field can be walked through
  it without their live store waiting on them.

Both write through `ResellerStoreService::saveChat()`, so the validation is in one
place and cannot be bypassed by a second caller. An empty value is stored as
`NULL`, so "not set" is one value rather than two, however the form was submitted.

## If you change the widget later

`ResellerChat::provider()` gives Tawk.To precedence when it is configured. Removing
the property id clears the widget id with it, so a leftover widget id cannot attach
itself to whatever property is set next.
