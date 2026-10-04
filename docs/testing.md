# Scripted replies for end-to-end tests

For Ghostwriter's own browser tests (the `ghostwriter-e2e` repository), the addon can answer every model call from a scripted scenario instead of a provider, so a test can drive the real control panel without spending tokens. It is for local development sites only and is off unless you set it up.

## When it is on

All of these must hold:

- `GHOSTWRITER_FAKE_SCENARIOS` in `.env` (config `ghostwriter.testing.fake_scenarios`) is set to the folder of scenario files. It is unset by default.
- `APP_ENV` is `local` or `testing` (never `production`).
- The request names a scenario: the `X-Ghostwriter-Fake: <scenario>` header, or the `ghostwriter_fake` cookie. The value is `<name>` or `<name>#<run>`, where the name is a file under the folder without `.json` (lower-case letters, digits, `-`, `_` and `/` only).

Requests without the header or cookie are untouched, so an editor working on the same site in their own browser still gets the real provider. A name that doesn't match a file stops the request with an error rather than falling back to a real provider.

Every job queued during such a request carries the scenario in its payload (`ghostwriterFake`); the queue worker plays it for that job and goes back to the real providers afterwards.

## Scenario files

Scenarios are JSON, read by core's `FakeScenario` (see core's `docs/providers.md`): replies per agent (`writer`, `brief-filler`, `layout-planner`, `reviewer`, `verifier` and so on), handed out in order across requests and jobs and counted in the cache per run. Agents a scenario doesn't list are answered from their schema.

## Setting it up on a test site

```
GHOSTWRITER_FAKE_SCENARIOS=/Users/you/Dev/ghostwriter-e2e/scenarios
```

Restart the queue worker after changing it (`php artisan queue:restart`). Never set it on a staging or production site. The code is in `src/Testing/FakeScenarios.php`.
