<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

/** Messages shown at the top of a page. */
final class FlashBag
{
    /** @var array<int,array{type:string,text:string}> */
    private array $messages = [];

    public function success(string $text): void
    {
        $this->add('ok', $text);
    }

    public function warning(string $text): void
    {
        $this->add('warn', $text);
    }

    public function error(string $text): void
    {
        $this->add('err', $text);
    }

    private function add(string $type, string $text): void
    {
        // Repeating the same message twice in one render is noise, not emphasis.
        foreach ($this->messages as $message) {
            if ($message['type'] === $type && $message['text'] === $text) {
                return;
            }
        }

        $this->messages[] = ['type' => $type, 'text' => $text];
    }

    /** @return array<int,array{type:string,text:string}> */
    public function all(): array
    {
        return $this->messages;
    }

    public function hasErrors(): bool
    {
        foreach ($this->messages as $message) {
            if ($message['type'] === 'err') {
                return true;
            }
        }

        return false;
    }
}
