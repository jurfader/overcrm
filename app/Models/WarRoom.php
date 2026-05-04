<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarRoom extends Model
{
    protected $fillable = [
        'code',
        'player1_id',
        'player2_id',
        'status',
        'player1_deck',
        'player2_deck',
        'war_pile',
        'last_player1_card',
        'last_player2_card',
        'current_turn',
        'winner_id',
    ];

    protected function casts(): array
    {
        return [
            'player1_deck' => 'array',
            'player2_deck' => 'array',
            'war_pile' => 'array',
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

    protected static function cardValue(string $card): int
    {
        $rank = substr($card, 0, -1);
        $values = ['2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9, '10' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14];

        return $values[$rank] ?? 0;
    }

    public static function createDeck(): array
    {
        $ranks = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
        $suits = ['S', 'H', 'D', 'C'];
        $deck = [];
        foreach ($suits as $s) {
            foreach ($ranks as $r) {
                $deck[] = $r . $s;
            }
        }
        shuffle($deck);

        return $deck;
    }

    public function play(string $player): bool
    {
        if ($this->status !== 'playing') {
            return false;
        }

        $deckKey = $player === 'player1' ? 'player1_deck' : 'player2_deck';
        $deck = $this->$deckKey ?? [];

        if (empty($deck)) {
            return false;
        }

        if ($this->current_turn !== $player) {
            return false;
        }

        $card = array_shift($deck);
        $this->$deckKey = $deck;

        $warPile = $this->war_pile ?? [];
        $warPile[] = $card;
        $this->war_pile = $warPile;

        if ($player === 'player1') {
            $this->last_player1_card = $card;
            $this->last_player2_card = null;
            $this->current_turn = 'player2';
        } else {
            $this->last_player2_card = $card;
            $this->resolveBattle();
            $this->current_turn = 'player1';
        }

        $this->last_activity_at = now();
        $this->save();

        return true;
    }

    protected function resolveBattle(): void
    {
        $c1 = $this->last_player1_card;
        $c2 = $this->last_player2_card;

        if (! $c1 || ! $c2) {
            return;
        }

        $v1 = self::cardValue($c1);
        $v2 = self::cardValue($c2);
        $warPile = $this->war_pile ?? [];

        if ($v1 > $v2) {
            $p1 = $this->player1_deck ?? [];
            $p1 = array_merge($p1, $warPile);
            $this->player1_deck = $p1;
            $this->war_pile = [];
        } elseif ($v2 > $v1) {
            $p2 = $this->player2_deck ?? [];
            $p2 = array_merge($p2, $warPile);
            $this->player2_deck = $p2;
            $this->war_pile = [];
        } else {
            // War - each player needs to add 3 more cards
            $p1 = $this->player1_deck ?? [];
            $p2 = $this->player2_deck ?? [];
            $add1 = min(3, count($p1));
            $add2 = min(3, count($p2));
            for ($i = 0; $i < $add1; $i++) {
                $warPile[] = array_shift($p1);
            }
            for ($i = 0; $i < $add2; $i++) {
                $warPile[] = array_shift($p2);
            }
            $this->player1_deck = $p1;
            $this->player2_deck = $p2;
            $this->war_pile = $warPile;
            $this->last_player1_card = null;
            $this->last_player2_card = null;
        }

        $p1Count = count($this->player1_deck ?? []);
        $p2Count = count($this->player2_deck ?? []);

        if ($p2Count === 0 && $p1Count > 0) {
            $this->status = 'finished';
            $this->winner_id = $this->player1_id;
        } elseif ($p1Count === 0 && $p2Count > 0) {
            $this->status = 'finished';
            $this->winner_id = $this->player2_id;
        }
    }
}
