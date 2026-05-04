<?php

namespace App\Http\Controllers;

use App\Models\BattleshipRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BattleshipController extends Controller
{
    public function createRoom(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $code = BattleshipRoom::generateCode();
        $room = BattleshipRoom::create([
            'code' => $code,
            'player1_id' => $user->id,
            'status' => 'waiting',
            'player1_ships' => BattleshipRoom::placeShipsRandomly(),
            'player1_hits' => [],
            'player2_hits' => [],
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

        $room = BattleshipRoom::where('code', strtoupper($code))->first();
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

        $room = BattleshipRoom::where('code', strtoupper($code))->where('status', 'waiting')->first();
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
            'player2_ships' => BattleshipRoom::placeShipsRandomly(),
            'status' => 'playing',
            'last_activity_at' => now(),
        ]);

        return response()->json([
            'room' => $this->formatRoom($room->fresh(), $user->id),
            'role' => 'player2',
        ]);
    }

    public function makeShot(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $request->validate([
            'row' => 'required|integer|min:0|max:9',
            'col' => 'required|integer|min:0|max:9',
        ]);

        $room = BattleshipRoom::where('code', strtoupper($code))->first();
        if (! $room) {
            return response()->json(['message' => 'Nie znaleziono gry'], 404);
        }

        $role = $room->player1_id === $user->id ? 'player1' : 'player2';
        if ($room->player2_id !== $user->id && $room->player1_id !== $user->id) {
            return response()->json(['message' => 'Nie jesteś w tej grze'], 403);
        }

        if (! $room->makeShot($role, (int) $request->row, (int) $request->col)) {
            return response()->json(['message' => 'Nieprawidłowy strzał'], 422);
        }

        return response()->json([
            'room' => $this->formatRoom($room->fresh(), $user->id),
        ]);
    }

    private function formatRoom(BattleshipRoom $room, int $currentUserId): array
    {
        $role = $room->player1_id === $currentUserId ? 'player1' : 'player2';
        $myShips = $role === 'player1' ? $room->player1_ships : $room->player2_ships;
        $enemyShips = $role === 'player1' ? $room->player2_ships : $room->player1_ships;
        $myHits = $role === 'player1' ? ($room->player1_hits ?? []) : ($room->player2_hits ?? []);
        $enemyHits = $role === 'player1' ? ($room->player2_hits ?? []) : ($room->player1_hits ?? []);

        $myShots = [];
        $enemyGrid = $enemyShips ?? array_fill(0, 10, array_fill(0, 10, 0));
        foreach ($myHits as $h) {
            $r = $h[0] ?? 0;
            $c = $h[1] ?? 0;
            $myShots[] = ['r' => $r, 'c' => $c, 'hit' => ($enemyGrid[$r][$c] ?? 0) > 0];
        }

        $mySunkCount = $enemyShips ? BattleshipRoom::countSunk($enemyShips, $myHits) : 0;
        $enemySunkCount = $myShips ? BattleshipRoom::countSunk($myShips, $enemyHits) : 0;

        return [
            'id' => $room->id,
            'code' => $room->code,
            'status' => $room->status,
            'current_turn' => $room->current_turn,
            'winner_id' => $room->winner_id,
            'player1' => ['id' => $room->player1_id, 'name' => $room->player1?->name ?? 'Gracz 1'],
            'player2' => $room->player2_id ? ['id' => $room->player2_id, 'name' => $room->player2?->name ?? 'Gracz 2'] : null,
            'my_ships' => $myShips ?? array_fill(0, 10, array_fill(0, 10, 0)),
            'my_shots' => $myShots,
            'enemy_hits' => $enemyHits,
            'my_sunk_count' => $mySunkCount,
            'enemy_sunk_count' => $enemySunkCount,
            'is_your_turn' => $room->status === 'playing' && $room->current_turn === $role,
        ];
    }
}
