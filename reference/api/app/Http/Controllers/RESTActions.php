<?php namespace App\Http\Controllers;

use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;


trait RESTActions {

    protected $statusCodes = [
        'done' => 200,
        'created' => 201,
        'removed' => 204,
        'not_valid' => 400,
        'not_allowed' => 401,
        'not_found' => 404,
        'conflict' => 409,
        'unprocessable' => 422,
    ];

    private function has_permission ($user, $required_permissions) {
        $role_names = [];
        $has = false;
        foreach ($user->roles as $role) {
            array_push($role_names, $role->type);
        }
        $intersect = array_intersect($required_permissions, $role_names);
        if (count($intersect) > 0) {
            $has = true;
        }
        return $has;
    }

    protected function get_actionable_properties (Request $request, $model, $operation) {
        $user = $request->user();
        $m = get_class($model);
        $properties = $m::$PROPERTIES;
        $actionable_properties = [];
        foreach ($properties as $property) {
            $permissions = $m::$PROPERTIES_PERMISSIONS;
            $is_valid = false;
            if (in_array(self::ALL, $permissions[$property][$operation])) {

                $is_valid = true;
            } else if (in_array(self::NONE, $permissions[$property][$operation])) {
                $is_valid = false;
            } else if (in_array(self::MY, $permissions[$property][$operation])) {
                if ($m === 'App\\User') {
                    $is_valid = ($model->id === $user->id);
                } else {
                    // try to get the single user which this instance belongs to
                    $assigned_user = $model->user;
                    if (is_null($assigned_user)) {
                        if ($model->users !== null) {
                            foreach ($model->users as $mult_user) {
                                if ($mult_user->id === $user->id) {
                                    $is_valid = true;
                                    break;
                                }
                            }
                        }
                    } else {
                        if ($assigned_user->id === $user->id) {
                            $is_valid = true;
                        }
                    }
                }
            }
            if (!$is_valid) {
                $is_valid = $this->has_permission($user, $permissions[$property][$operation]);
            }
            if ($is_valid) {
                array_push($actionable_properties, $property);
            }
        }
        return $actionable_properties;
    }

    protected function set_visible_properties (Request $request, $model) {
        $visible_properties = $this->get_actionable_properties($request, $model, 'read');
        if (count($visible_properties) > 0) {
            $model->setVisible($visible_properties);
        } else {
            $m = get_class($model);
            $model->makeHidden($m::$PROPERTIES);
        }
    }

    protected function set_editable_properties (Request $request, $model) {
        $func = 'update';
        if ($request->method() === 'POST') {
            $func = 'create';
        }
        $fillable_properties = $this->get_actionable_properties($request, $model, $func);
        $model->fillable($fillable_properties);
        if (count($fillable_properties) === 0) {
            $m = get_class($model);
            $model->guard($m::$PROPERTIES);
        } else {
            $model->guard([]);
        }
    }

    protected function can_do_relation (User $user, $model, $relation_name, $action, $relation = null) {
        $m = get_class($model);
        $permissions = $m::$RELATIONSHIP_PERMISSIONS;
        $permission = $permissions[$relation_name][$action];
        $allowed = false;
        if (in_array(self::NONE, $permission)) {
            $allowed = false;
        } else if (in_array(self::ALL, $permission)) {
            $allowed = true;
        } else if ($this->has_permission($user, $permission)) {
            $allowed = true;
        } else if (in_array(self::MY, $permission)) {
            if (get_class($user) === get_class($model)) {
                if ($user->id === $model->id) {
                    if ($relation === null) {
                        $allowed = true;
                    } else {
                        $test = $model->{$relation_name}()->where('id', $relation->id)->first();
                        if ($test === null) {
                            $allowed = false;
                        } else {
                            $allowed = true;
                        }
                    }
                }
            } else {
                // type self means: the model is associated with the currently logged in user
                $model_type = $model::TYPE;
                $relationship_types = $user::$RELATIONSHIPS;
                $associated = false;
                foreach ($relationship_types as $relationship_type) {
                    if ($allowed) {
                        break;
                    }
                    foreach ($relationship_type as $name => $description) {
                        if ($allowed) {
                            break;
                        }
                        if ($description['type'] === $model_type) {
                            $entities = $user->{$name};
                            foreach ($entities as $entity) {
                                if ((get_class($entity) === get_class($model)) && ($entity->id === $model->id)) {
                                    $allowed = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $allowed;
    }

    protected function can_view_relation (User $user, $model, $relation_name, $relation = null) {
        return $this->can_do_relation($user, $model, $relation_name, 'read', $relation);
    }

    protected function can_create_relation (User $user, $model, $relation_name, $relation = null) {
        return $this->can_do_relation($user, $model, $relation_name, 'create', $relation);
    }

    protected function can_delete_relation (User $user, $model, $relation_name, $relation = null) {
        return $this->can_do_relation($user, $model, $relation_name, 'delete', $relation);
    }

    private function get_view (Request $request, $model) {
        $m = get_class($model);
        $relationships = [];
        $included = [];
        $user = $request->user();
        $all_relations = array_merge($m::$RELATIONSHIPS['belongs_to'], $m::$RELATIONSHIPS['has_many'], $m::$RELATIONSHIPS['belongs_to_and_has_many']);
        // refresh relationships from db
        $keys = array_keys($all_relations);
        $model->load($keys);
        foreach ($all_relations as $name => $description) {
            $relations = $model->{$name}; // e.g. comments
            if ($relations !== null) {
                if (array_key_exists($name, $m::$RELATIONSHIPS['has_many']) || array_key_exists($name, $m::$RELATIONSHIPS['belongs_to_and_has_many'])) {
                    $relationship_array = [];
                    foreach($relations as $relation) {
                        if ($this->can_view_relation($user, $model, $name)) {
                            $items = $this->get_relation_item_array($request, $description, $relation);
                            array_push($relationship_array, $items['relation_item']);
                            array_push($included, $items['inclusion_item']);
                        }
                    }
                    if (count($relationship_array) > 0) {
                        $relationships[$name] = $relationship_array;
                    }
                } else if (array_key_exists($name, $m::$RELATIONSHIPS['belongs_to'])) {
                    $relation = $relations;
                    if ($this->can_view_relation($user, $model, $name)) {
                        $item = $this->get_relation_item_array($request, $description, $relation);
                        $relationships[$name] = $item['relation_item'];
                        array_push($included, $item['inclusion_item']);
                    }
                }
            }
        }

        $link = null;
        $link_segments = $request->segments();
        if ((count($link_segments) > 0) && is_numeric($link_segments[count($link_segments) - 1])) {
            $link = $request->url();
        } else {
            $link = $request->url() . '/' . $model->id;
        }
        $this->set_visible_properties($request, $model);
        $value = [
            'data' => [
                'type' => self::TYPE,
                'id' => $model->id,
                'attributes' => $model,
                'links' => [
                    'self' => $link
                ],
                'relationships' => $relationships
            ],
            'included' => $included
        ];
        return $value;
    }

    private function get_relation_item_array (Request $request, $description, $relation) {
        $relation_item = [];

        $link = $request->root() . config('names.path.' . $description['type']). '/' . $relation->id;
        $relation_item['links'] = [
            'self' => $link
        ];
        $this->set_visible_properties($request, $relation);
        $relation_item['attributes'] = [
            'id' => $relation->id,
            'type' => $description['type'],
            'attributes' => $relation
        ];

        $inclusion_item = [];
        $inclusion_item['type'] = $description['type'];
        $inclusion_item['id'] = $relation->id;
        $inclusion_item['attributes'] = $relation;
        $inclusion_item['links'] = ['self' => $link];
        return [
            'relation_item' => $relation_item,
            'inclusion_item' => $inclusion_item
        ];
    }

    private function save_model (Request $request, $model, $fullOverwrite = false) {
        $m = get_class($model);
        $this->set_editable_properties($request, $model);
        if ($fullOverwrite) {
            $attributes = $model->getAttributes();
            foreach ($attributes as $key => $value) {
                $attributes[$key] = null;
            }
            $model->fill($attributes);
        }
        $model->fill($request->input());
        $user = $request->user();
        if (array_key_exists('user', $m::$RELATIONSHIPS['belongs_to'])) {
            if ($this->can_create_relation($user, $model, 'user', $user)) {
                $model->user()->associate($user);
            }
        }
        $save_relations = function ($relation_name) use ($model, $request, $fullOverwrite, $user) {
            $type = array_search($relation_name, config('names.plural'));
            $className = null;
            if ($type === null) {
                $className = 'App\\' . config('names.class.' . $relation_name);
            } else {
                $className = 'App\\' . config('names.class.' . $type);
            }
            if ($request->has($relation_name)) {
                $id = $request->input($relation_name);
                if (is_numeric($id)) { // belongs to relationship
                    $relation = $className::find($id);
                    if ($this->can_create_relation($user, $model, $relation_name, $relation)) {
                        $model->{$relation_name}()->associate($id);
                    }
                } else if (is_array($id)) {
                    // delete all items that were in relationship before, but not after the update
                    // get class name
                    $key = array_search($relation_name, config('names.plural'));
                    // get instances of the references items
                    $links = $className::find($id);
                    $new_ids = $id;
                    
                    $relations = $model->{$relation_name};
                    $old_ids = [];
                    foreach($relations as $rel) {
                        array_push($old_ids, $rel->id);
                    }
                    $in_old_but_not_in_new = array_diff($old_ids, $new_ids);
                    if ($this->can_delete_relation($user, $model, $relation_name)) {
                        $className::destroy($in_old_but_not_in_new);
                    }

                    foreach ($links as $link) {
                        if ($this->can_create_relation($user, $model, $relation_name, $link)) {
                            $model->{$relation_name}()->save($link);
                        }
                    }
                }
            }
            else {
                // request has no definition of this relation
                if ($fullOverwrite) {
                    $relationType = get_class($model->{$relation_name}());
                    if ('Illuminate\Database\Eloquent\Relations\BelongsTo' === $relationType) {
                        $value = ['error' => 'belongs to relation is missing'];
                        $this->respond('unprocessable', $value);
                    } else if ('Illuminate\Database\Eloquent\Relations\HasMany' === $relationType) {
                        $instances = $model->{$relation_name};
                        $ids = [];
                        foreach ($instances as $item) {
                            array_push($ids, $item->id);
                        }
                        if ($this->can_destroy_relation($user, $model, $type)) {
                            $className::destroy($ids);
                        }
                    }
                }
            }
        };
        $relationships_belongs_to = array_keys($m::$RELATIONSHIPS['belongs_to']);
        array_walk($relationships_belongs_to, $save_relations);
        if ($model->id === null) {
            try {
                $model->save();
            } catch (Exception $e) {
                $value = [
                    'error' => 'Bad Request',
                    'details' => 'Could not do this because the given model was invalid.'
                ];
                $this->respond('not_valid', $value);
            }
        }
        $relationships_has_many = array_keys($m::$RELATIONSHIPS['has_many']);
        array_walk($relationships_has_many, $save_relations);
        $relationships_belongs_to_and_has_many = array_keys($m::$RELATIONSHIPS['belongs_to_and_has_many']);
        array_walk(
            $relationships_belongs_to_and_has_many,
            function ($relation_name) use ($model, $request, $fullOverwrite, $user, $m) {
                $relation_array = null;
                if ($request->input($relation_name) === null) {
                    if ($fullOverwrite) {
                        $relation_array = [];
                    }
                }
                else {
                    $relation_array = $request->input($relation_name);
                }
                if ($model->{$relation_name}() && $relation_array !== null) {
                    $items = $model->{$relation_name};
                    $old_ids = [];
                    foreach ($items as $item) {
                        array_push($old_ids, $item->id);
                    }
                    $in_both = array_intersect($old_ids, $relation_array);
                    $in_old_but_not_in_new = array_diff($old_ids, $in_both);
                    $sync_in_old_but_not_in_new = [];
                    $relation_conf = $m::$RELATIONSHIPS['belongs_to_and_has_many'][$relation_name];
                    $model_type = $relation_conf['type'];
                    foreach ($in_old_but_not_in_new as $old_id) {
                        $class_name = 'App\\' . config('names.class.' . $model_type);
                        $instance = $class_name::find($old_id);
                        if (!$this->can_delete_relation($user, $model, $relation_name, $instance)) {
                            // cannot delete relation, so keep it
                            array_push($sync_in_old_but_not_in_new, $old_id);
                        }
                    }
                    $in_new_but_not_in_old = array_diff($relation_array, $in_both);
                    $sync_in_new_but_not_in_old = [];
                    foreach ($in_new_but_not_in_old as $new_id) {
                        if ($this->can_create_relation($user, $model, $relation_name, $old_id)) {
                            $class_name = 'App\\' . config('names.class.' . $model_type);
                            $instance = $class_name::find($new_id);
                            // cannot delete relation, so keep it
                            array_push($sync_in_new_but_not_in_old, $new_id);
                        }
                    }
                    $sync = array_merge($in_both, $sync_in_new_but_not_in_old, $sync_in_old_but_not_in_new);
                    try {
                        $model->{$relation_name}()->sync($sync);
                    } catch (Exception $e) {
                        $value = ['error' => 'heise'];
                        return $this->respond('not_valid', $value);
                    }
                }
            }
        );
        $model->save();
    }

    private function get_validation_exception_values (ValidationException $e) {
        $details = [];
        foreach ($e->getResponse()->getData() as $key => $message) {
            array_push($details, $message);
        }
        $value = [
            'error' => 'The data was not correct',
            'details' => $details
        ];
        return $value;
    }

    public function all (Request $request) {
        $m = self::MODEL;
        $models = $m::all();
        $user = $request->user();
        $items = [];
        foreach ($models as $model) {
            if ($user->can('view', $model)) {
                array_push($items, $model);
            }
        }
        $values = [];
        foreach ($items as $item) {
            $value = $this->get_view($request, $item);
            array_push($values, $value);
        }
        return $this->respond('done', $values);
    }

    public function view (Request $request, $id) {
        $m = self::MODEL;
        $model = $m::find($id);
        if(is_null($model)){
            $value = ['error' => 'Model not found'];
            return $this->respond('not_found', $value);
        }
        $value = $this->get_view($request, $model);
        return $this->respond('done', $value);
    }

    public function create (Request $request) {
        $m = self::MODEL;
        try {
            $this->validate($request, $m::VALIDATION($request));
        } catch (ValidationException $e) {
            $value = $this->get_validation_exception_values($e);
            return $this->respond('unprocessable', $value);
        }

        $model = new $m;
        $this->save_model($request, $model);

        // handle output
        $value = $this->get_view($request, $model);
        return $this->respond('created', $value);
    }

    protected function handle_update (Request $request, $id, $fullOverwrite) {
        $m = self::MODEL;
        $model = $m::find($id);
        if (is_null($model)){
            return $this->respond('not_found');
        }
        try {
            $validation = $m::VALIDATION($request, $model);
            if (!$fullOverwrite) { // just check the given properties
                $editable_properties = $this->get_actionable_properties($request, $model, 'update');
                $validation_properties = array_intersect($m::$PROPERTIES, $editable_properties, array_keys($request->input()));
                $validation_trim = [];
                array_walk($validation_properties, function ($property) use (&$validation_trim, &$validation) {
                    if (array_key_exists($property, $validation)) {
                        $validation_trim[$property] = $validation[$property];
                    }
                    // print_r($validation_trim);
                });
                $validation = $validation_trim;
            }
            $this->validate($request, $validation);
        } catch (ValidationException $e) {
            $value = $this->get_validation_exception_values($e);
            return $this->respond('not_valid', $value);
        }
        $this->save_model($request, $model, $fullOverwrite);

        // handle output
        $value = $this->get_view($request, $model);
        return $this->respond('done', $value);
    }

    public function patch (Request $request, $id) {
        return $this->handle_update($request, $id, false);
    }

    public function update (Request $request, $id) {
        return $this->handle_update($request, $id, true);
    }

    public function delete ($id) {
        $m = self::MODEL;
        if(is_null($m::find($id))){
            return $this->respond('not_found');
        }
        $m::destroy($id);
        return $this->respond('removed');
    }

    protected function respond ($status, $data = [])
    {
        return response()->json($data, $this->statusCodes[$status]);
    }

}