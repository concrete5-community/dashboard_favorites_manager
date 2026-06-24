<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Message;

defined('C5_EXECUTE') or die('Access Denied.');

class OverlayMessageQueue
{
    private const SESSION_KEY = 'dashboard_favorites_manager_overlay_messages';

    private $session;

    public function __construct($session)
    {
        $this->session = $session;
    }

    public function add($type, $message)
    {
        $type = $this->normalizeType($type);
        $message = trim((string) $message);
        if ($message === '') {
            return;
        }

        try {
            $messages = $this->session->get(self::SESSION_KEY, []);
            if (!is_array($messages)) {
                $messages = [];
            }
            $messages[] = [
                'type' => $type,
                'message' => $message,
            ];
            $this->session->set(self::SESSION_KEY, $messages);
        } catch (\Throwable $e) {
            // Overlay messages are optional feedback; never block the action.
        }
    }

    public function pull()
    {
        try {
            $messages = $this->session->get(self::SESSION_KEY, []);
            $this->session->remove(self::SESSION_KEY);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($messages)) {
            return [];
        }

        $cleanMessages = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $text = trim((string) ($message['message'] ?? ''));
            if ($text === '') {
                continue;
            }
            $cleanMessages[] = [
                'type' => $this->normalizeType($message['type'] ?? 'info'),
                'message' => $text,
            ];
        }

        return $cleanMessages;
    }

    private function normalizeType($type)
    {
        $type = (string) $type;
        if ($type === 'danger') {
            return 'error';
        }

        return in_array($type, ['success', 'warning', 'error', 'info'], true) ? $type : 'info';
    }
}
