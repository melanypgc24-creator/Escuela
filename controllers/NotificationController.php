<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\JsonResponse;
use App\Models\NotificationModel;

final class NotificationController
{
    public function __construct(private NotificationModel $notifications)
    {
    }

    public function index(): void
    {
        JsonResponse::send([
            'data' => $this->notifications->allForUser((int) $_SESSION['id_usuario']),
        ]);
    }

    public function markRead(array $params): void
    {
        $id = (int) $params['id'];
        if (!$this->notifications->markRead($id, (int) $_SESSION['id_usuario'])) {
            JsonResponse::send(['error' => 'Notificación no encontrada.'], 404);
            return;
        }
        JsonResponse::send(['message' => 'Notificación marcada como leída.']);
    }
}
