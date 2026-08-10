<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Dostęp do pojedynczego zadania.
 *
 * Uprawnienie `tasks_manage` odpowiada na pytanie „czy ta osoba w ogóle może
 * zarządzać zadaniami", a ta polityka na pytanie „czy może zarządzać TYM
 * zadaniem". Samo uprawnienie nie wystarcza — inaczej każdy handlowiec
 * z dostępem do modułu zadań czytałby zadania zarządu.
 */
class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $task->isAccessibleBy($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $task->isAccessibleBy($user);
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->isAccessibleBy($user);
    }

    /**
     * Komentowanie wymaga tego samego dostępu co podgląd — kto widzi zadanie,
     * ten może się w nim wypowiedzieć.
     */
    public function comment(User $user, Task $task): bool
    {
        return $task->isAccessibleBy($user);
    }
}
