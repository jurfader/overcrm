<?php

namespace App\Http\Controllers;

use App\Models\WarRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarController extends Controller
{
    public function createRoom(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $deck = WarRoom::createDeck();
        $player1Deck = array_slice($deck, 0, 26);
        $player2Deck = array_slice($deck, 26);

        $code = WarRoom::generateCode();
        $room = WarRoom::create([
            'code' => $code,
            'player1_id' => $user->id,
            'status' => 'waiting',
            'player1_deck' => $player1Deck,
            'player2_deck' => $player2Deck,
            'war_pile' => [],
            'current_turn' => 'player1',
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'room' => $this->formatRoom($room, $user->id),
            'role' => 'player1',
        ]);
    }

    public function getRoom(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $room = WarRoom::where('code', strtoupper($code))->first();
        if (! $room) {
            return response()->json(['message' => 'Nie znaleziono gry'], 404);
        }

        if ($room->player1_id !== $user->id && $room->player2_id !== $user->id) {
            return response()->json(['message' => 'Nie jesteś w tej grze'], 403);
        }

        $role = $room->player1_id === $user->id ? 'player1' : 'player2';

        return response()->json([
            'room' => $this->formatRoom($room, $user->id),
            'role' => $role,
        ]);
    }

    public function joinRoom(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $room = WarRoom::where('code', strtoupper($code))->where('status', 'waiting')->first();
        if (! $room) {
            return response()->json(['message' => 'Gra nie istnieje lub już się rozpoczęła'], 404);
        }

        if ($room->player1_id === $user->id) {
            return response()->json([
                'room' => $this->formatRoom($room, $user->id),
                'role' => 'player1',
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
            'room' => $this->formatRoom($room->fresh(), $user->id),
            'role' => 'player2',
        ]);
    }

    public function play(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $room = WarRoom::where('code', strtoupper($code))->first();
        if (! $room) {
            return response()->json(['message' => 'Nie znaleziono gry'], 404);
        }

        $role = $room->player1_id === $user->id ? 'player1' : 'player2';
        if ($room->player2_id !== $user->id && $room->player1_id !== $user->id) {
            return response()->json(['message' => 'Nie jesteś w tej grze'], 403);
        }

        if (! $room->play($role)) {
            return response()->json(['message' => 'Nieprawidłowy ruch'], 422);
        }

        return response()->json([
            'room' => $this->formatRoom($room->fresh(), $user->id),
        ]);
    }

    private function formatRoom(WarRoom $room, int $currentUserId): array
    {
        $role = $room->player1_id === $currentUserId ? 'player1' : 'player2';
        $myDeck = $role === 'player1' ? ($room->player1_deck ?? []) : ($room->player2_deck ?? []);
        $enemyDeck = $role === 'player1' ? ($room->player2_deck ?? []) : ($room->player1_deck ?? []);

        $lastPlayerCard = $role === 'player1' ? $room->last_player1_card : $room->last_player2_card;
        $lastEnemyCard = $role === 'player1' ? $room->last_player2_card : $room->last_player1_card;

        return [
            'id' => $room->id,
            'code' => $room->code,
            'status' => $room->status,
            'current_turn' => $room->current_turn,
            'winner_id' => $room->winner_id,
            'player1' => ['id' => $room->player1_id, 'name' => $room->player1?->name ?? 'Gracz 1'],
            'player2' => $room->player2_id ? ['id' => $room->player2_id, 'name' => $room->player2?->name ?? 'Gracz 2'] : null,
            'player1_deck' => $myDeck,
            'player2_deck' => array_fill(0, count($enemyDeck), '?'),
            'last_player_card' => $lastPlayerCard,
            'last_enemy_card' => $lastEnemyCard,
            'is_your_turn' => $room->status === 'playing' && $room->current_turn === $role,
        ];
    }
}
