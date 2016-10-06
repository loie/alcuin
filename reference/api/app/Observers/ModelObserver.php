<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

trait ModelObserver
{
    /**
     * Listen to the User deleting event.
     *
     * @param  User  $user
     * @return void
     */
    public function deleting (Model $model)
    {
        $className = get_class($model);
        $relationships = $className::$RELATIONSHIPS;
        foreach (array_keys($relationships['has_many']) as $relationship) {
                echo 
                $model->{$relationship}()->delete();
        }
        foreach (array_keys($relationships['belongs_to_and_has_many']) as $relationship) {
            $model->{$relationship}()->detach();
        }
    }
}