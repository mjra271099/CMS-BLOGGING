<?php
declare(strict_types=1);

namespace App\Core;

/**
 * One-request flash messages with severity levels.
 */
final class Flash
{
    public function add(string $type, string $message): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public function success(string $message): void
    {
        $this->add('success', $message);
    }

    public function error(string $message): void
    {
        $this->add('error', $message);
    }

    public function warning(string $message): void
    {
        $this->add('warning', $message);
    }

    public function pull(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($messages) ? $messages : [];
    }
}
