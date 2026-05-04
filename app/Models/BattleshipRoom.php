<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BattleshipRoom extends Model
{
    const SIZE = 10;

    const SHIPS = [5, 4, 3, 3, 2];

    protected $fillable = [
        'code',
        'player1_id',
        'player2_id',
        'status',
        'player1_ships',
        'player2_ships',
        'player1_hits',
        'player2_hits',
        'current_turn',
        'winner_id',
    ];

    protected function casts(): array
    {
        return [
            'player1_ships' => 'array',
            'player2_ships' => 'array',
            'player1_hits' => 'array',
            'player2_hits' => 'array',
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

    public static function placeShipsRandomly(): array
    {
        $grid = [];
        for ($i = 0; $i < self::SIZE; $i++) {
            $grid[] = array_fill(0, self::SIZE, 0);
        }
        foreach (self::SHIPS as $len) {
            $placed = false;
            for ($attempt = 0; $attempt < 200 && ! $placed; $attempt++) {
                $row = random_int(0, self::SIZE - 1);
                $col = random_int(0, self::SIZE - 1);
                $horizontal = (bool) random_int(0, 1);
                if (self::canPlace($grid, $row, $col, $len, $horizontal)) {
                    self::placeShip($grid, $row, $col, $len, $horizontal);
                    $placed = true;
                }
            }
        }

        return $grid;
    }

    protected static function canPlace(array $grid, int $row, int $col, int $len, bool $horizontal): bool
    {
        $r = $horizontal ? 1 : $len;
        $c = $horizontal ? $len : 1;
        for ($i = -1; $i <= $r; $i++) {
            for ($j = -1; $j <= $c; $j++) {
                $nr = $row + $i;
                $nc = $col + $j;
                if ($nr >= 0 && $nr < self::SIZE && $nc >= 0 && $nc < self::SIZE && ($grid[$nr][$nc] ?? 0)) {
                    return false;
                }
            }
        }
        for ($k = 0; $k < $len; $k++) {
            $nr = $row + ($horizontal ? 0 : $k);
            $nc = $col + ($horizontal ? $k : 0);
            if ($nr < 0 || $nr >= self::SIZE || $nc < 0 || $nc >= self::SIZE) {
                return false;
            }
        }

        return true;
    }

    protected static function placeShip(array &$grid, int $row, int $col, int $len, bool $horizontal): void
    {
        for ($k = 0; $k < $len; $k++) {
            $nr = $row + ($horizontal ? 0 : $k);
            $nc = $col + ($horizontal ? $k : 0);
            $grid[$nr][$nc] = $len;
        }
    }

    public static function countSunk(array $ships, array $hits): int
    {
        $sunk = [];
        foreach ($hits as $h) {
            $r = $h[0] ?? null;
            $c = $h[1] ?? null;
            if ($r === null || $c === null) {
                continue;
            }
            $id = $ships[$r][$c] ?? 0;
            if ($id && self::isSunk($ships, $hits, $id)) {
                $sunk[$id] = true;
            }
        }

        return count($sunk);
    }

    protected static function isSunk(array $ships, array $hits, int $shipId): bool
    {
        $count = 0;
        for ($r = 0; $r < self::SIZE; $r++) {
            for ($c = 0; $c < self::SIZE; $c++) {
                if (($ships[$r][$c] ?? 0) === $shipId) {
                    foreach ($hits as $h) {
                        if (($h[0] ?? -1) === $r && ($h[1] ?? -1) === $c) {
                            $count++;
                            break;
                        }
                    }
                }
            }
        }

        return $count === $shipId;
    }

    public function makeShot(string $player, int $row, int $col): bool
    {
        $hitsKey = $player === 'player1' ? 'player1_hits' : 'player2_hits';
        $shipsKey = $player === 'player1' ? 'player2_ships' : 'player1_ships';
        $hits = $this->$hitsKey ?? [];
        $ships = $this->$shipsKey ?? [];

        foreach ($hits as $h) {
            if (($h[0] ?? -1) === $row && ($h[1] ?? -1) === $col) {
                return false;
            }
        }

        if ($this->current_turn !== $player || $this->status !== 'playing') {
            return false;
        }

        $hits[] = [$row, $col];
        $this->$hitsKey = $hits;
        $this->current_turn = $player === 'player1' ? 'player2' : 'player1';
        $this->last_activity_at = now();

        $sunkCount = self::countSunk($ships, $hits);
        if ($sunkCount === 5) {
            $this->status = 'finished';
            $this->winner_id = $player === 'player1' ? $this->player1_id : $this->player2_id;
        }

        $this->save();

        return true;
    }
}
