<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GameRoom extends Model
{
    protected $fillable = [
        'code',
        'player1_id',
        'player2_id',
        'status',
        'board',
        'current_turn',
        'winner_id',
    ];

    protected function casts(): array
    {
        return [
            'board' => 'array',
        ];
    }

    public function player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player2_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public static function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function getBoard(): array
    {
        return $this->board ?? array_fill(0, 9, null);
    }

    public function checkWinner(): ?string
    {
        $board = $this->getBoard();
        $lines = [
            [0, 1, 2], [3, 4, 5], [6, 7, 8],
            [0, 3, 6], [1, 4, 7], [2, 5, 8],
            [0, 4, 8], [2, 4, 6],
        ];
        foreach ($lines as $line) {
            $a = $board[$line[0]] ?? null;
            $b = $board[$line[1]] ?? null;
            $c = $board[$line[2]] ?? null;
            if ($a && $a === $b && $b === $c) {
                return $a;
            }
        }
        return null;
    }

    public function isDraw(): bool
    {
        return ! in_array(null, $this->getBoard(), true) && ! $this->checkWinner();
    }

    public function makeMove(int $cell, string $mark, int $userId): bool
    {
        if ($cell < 0 || $cell > 8) {
            return false;
        }
        $board = $this->getBoard();
        if ($board[$cell] !== null) {
            return false;
        }
        $expectedMark = $this->current_turn;
        if ($mark !== $expectedMark) {
            return false;
        }
        $playerId = $mark === 'x' ? $this->player1_id : $this->player2_id;
        if ($userId !== $playerId) {
            return false;
        }
        $board[$cell] = $mark;
        $this->board = $board;
        $this->current_turn = $mark === 'x' ? 'o' : 'x';
        $this->last_activity_at = now();

        $winner = $this->checkWinner();
        if ($winner) {
            $this->status = 'finished';
            $this->winner_id = $winner === 'x' ? $this->player1_id : $this->player2_id;
        } elseif ($this->isDraw()) {
            $this->status = 'finished';
        }

        $this->save();

        return true;
    }
}
