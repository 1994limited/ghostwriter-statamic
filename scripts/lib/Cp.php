<?php

/**
 * Driving Statamic's Control Panel through a DevTools socket: signing in,
 * and the small chores every shot needs.
 */
class Cp
{
    public function __construct(private DevToolsSocket $ws, private string $url) {}

    public function signIn(string $email, string $password): void
    {
        $this->ws->navigate($this->url.'/cp/auth/login');

        // The form is Vue, so values go in through the native setter and an
        // input event, as typing would.
        $this->ws->evaluate(<<<'JS'
            (() => {
                const set = (el, value) => { const s = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; s.call(el, value); el.dispatchEvent(new Event('input', {bubbles: true})); };
                set(document.querySelector('input[type=email], input[name=email], input[type=text]'), EMAIL);
                set(document.querySelector('input[type=password]'), PASSWORD);
                document.querySelector('button[type=submit]').click();
            })()
        JS, ['EMAIL' => $email, 'PASSWORD' => $password]);

        $this->ws->waitFor(fn () => str_contains((string) $this->ws->evaluate('document.location.pathname'), '/cp') && ! str_contains((string) $this->ws->evaluate('document.location.pathname'), '/auth/login'), 15);
    }

    /**
     * A trial-mode site greets each fresh browser with a licensing notice.
     */
    public function snooze(): void
    {
        $this->ws->evaluate("[...document.querySelectorAll('button')].find(b => /snooze/i.test(b.textContent))?.click()");
    }

    /**
     * Click the first element whose text matches, as a person would.
     */
    public function click(string $text): bool
    {
        return (bool) $this->ws->evaluate(<<<'JS'
            (() => {
                const el = [...document.querySelectorAll('button, a, [role=button], label')].find(e => e.offsetParent !== null && new RegExp(TEXT, 'i').test(e.textContent.trim()));
                if (!el) return false;
                el.scrollIntoView({block: 'center'});
                el.click();
                return true;
            })()
        JS, ['TEXT' => $text]);
    }

    /**
     * Type into the focused element one character at a time, so it reads as
     * typing on screen.
     */
    public function type(string $text, int $perChar = 40000): void
    {
        foreach (mb_str_split($text) as $char) {
            $this->ws->send('Input.insertText', ['text' => $char]);
            usleep($perChar);
        }
    }
}
