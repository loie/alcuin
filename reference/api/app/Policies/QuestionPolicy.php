<?php

namespace App\Policies;

use App\User;
use App\Role;

class QuestionPolicy
{
    public function create(User $user) {
        $isAllowed = false;
        foreach ($user->roles as $role) {
            if (in_array($role->type, ['user', 'admin'])) {
                $isAllowed = true;
                break;
            }
        }
        return $isAllowed;
    }

    public function view (User $user, Question $question) {
        $isAllowed = false;
        foreach ($user->roles as $role) {
            if (in_array($role->type, ['user', 'admin'])) {
                $isAllowed = true;
                break;
            }
        }
        $isAllowed = ($user->id === $question->user_id);
        return $isAllowed;
    }

    public function update (User $user, Question $question) {
        $isAllowed = false;
        foreach ($user->roles as $role) {
            if (in_array($role->type, ['user', 'admin'])) {
                $isAllowed = true;
                break;
            }
        }
        $isAllowed = ($user->id === $question->user_id);
        return $isAllowed;
    }
    
    public function delete (User $user, Question $question) {
        $isAllowed = false;
        foreach ($user->roles as $role) {
            if (in_array($role->type, ['user', 'admin'])) {
                $isAllowed = true;
                break;
            }
        }
        $isAllowed = ($user->id === $question->user_id);
        return $isAllowed;
    }
}