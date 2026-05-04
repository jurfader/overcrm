<?php

namespace App\Http\Controllers;

use App\Models\GameRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MultiplayerGameController extends Controller
{
    public function createRoom(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $code = GameRoom::generateCode();
        $room = GameRoom::create([
            'code' => $code,
            'player1_id' => $user->id,
            'status' => 'waiting',
            'board' => array_fill(0, 9, null),
            'current_turn' => 'x',
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'room' => $this->formatRoom($room, $user->id),
            'your_mark' => 'x',
        ]);
    }

    public function getRoom(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $room = GameRoom::where('code', strtoupper($code))->first();
        if (! $room) {
            return response()->json(['message' => 'Nie znaleziono gry'], 404);
        }

        if ($room->player1_id !== $user->id && $room->player2_id !== $user->id) {
            return response()->json(['message' => 'Nie jesteś w tej grze'], 403);
        }

        $yourMark = $room->player1_id === $user->id ? 'x' : 'o';

        return response()->json([
            'room' => $this->formatRoom($room, $user->id),
            'your_mark' => $yourMark,
        ]);
    }

    public function joinRoom(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $room = GameRoom::where('code', strtoupper($code))->where('status', 'waiting')->first();
        if (! $room) {
            return response()->json(['message' => 'Gra nie istnieje lub już się rozpoczęła'], 404);
        }

        if ($room->player1_id === $user->id) {
            return response()->json([
                'room' => $this->formatRoom($room, $user->id),
                'your_mark' => 'x',
            ]);
        }

        if ($room->player2_id) {
            return response()->json(['message' => 'Gra jest pełna'], 422);
        }

        $room->update([
            'player2_id' => $user->id,
            'status' => 'playing',
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'room' => $this->formatRoom($room, $user->id),
            'your_mark' => 'o',
        ]);
    }

    public function makeMove(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $request->validate([
            'cell' => 'required|integer|min:0|max:8',
            'mark' => 'required|in:x,o',
        ]);

        $room = GameRoom::where('code', strtoupper($code))->first();
        if (! $room) {
            return response()->json(['message' => 'Nie znaleziono gry'], 404);
        }

        if ($room->status !== 'playing') {
            return response()->json(['message' => 'Gra nie jest aktywna'], 422);
        }

        if (! $room->makeMove(
            (int) $request->cell,
            $request->mark,
            $user->id
        )) {
            return response()->json(['message' => 'Nieprawidłowy ruch'], 422);
        }

        $room = $room->fresh();
        $yourMark = $room->player1_id === $user->id ? 'x' : 'o';

        return response()->json([
            'room' => $this->formatRoom($room, $user->id),
            'your_mark' => $yourMark,
        ]);
    }

    private function formatRoom(GameRoom $room, int $currentUserId): array
    {
        return [
            'id' => $room->id,
            'code' => $room->code,
            'status' => $room->status,
            'board' => $room->getBoard(),
            'current_turn' => $room->current_turn,
            'winner_id' => $room->winner_id,
            'player1' => [
                'id' => $room->player1_id,
                'name' => $room->player1?->name ?? 'Gracz 1',
            ],
            'player2' => $room->player2_id ? [
                'id' => $room->player2_id,
                'name' => $room->player2?->name ?? 'Gracz 2',
            ] : null,
            'is_your_turn' => $room->status === 'playing' && (
                ($room->current_turn === 'x' && $room->player1_id === $currentUserId) ||
                ($room->current_turn === 'o' && $room->player2_id === $currentUserId)
            ),
        ];
    }
}
