<?php namespace App;

// other models
use App\User;
use App\Question;
use App\Tag;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model {

    const TYPE = 'question';

    protected $fillable = ['text', 'created', 'edited', 'accepted', 'upvotes', 'downvoted', 'dummy'];
    protected $guarded = [];
    protected $visible = ['text', 'created', 'edited', 'accepted', 'upvotes', 'downvoted', 'dummy'];
    protected $dates = [];

    public static $VALIDATION = [
        'text' => 'required|min:10'
    ];

    public static $RELATIONSHIPS = [
        'belongs_to' => ['user', 'answer'],
        'has_and_belongs_to_many' => ['tags']
    ];

    public $timestamps = false;

    public function questions () {
        return $this->hasMany('App\Question');
    }

    public function tags () {
        return $this->hasManyThrough('App\Tag');
    }

    public function user () {
        return $this->belongsTo('App\User');
    }

    // public static $rules = [
    //     "name" => "required",
    //     "age" => "integer|min:13",
    //     "email" => "email|unique:users,email_address",
    // ];

    // Relationships

    // public function project()
    // {
    //     return $this->belongsTo("App\Project");
    // }

    // public function accounts()
    // {
    //     return $this->hasMany("Tests\Tmp\Account");
    // }

    // public function owner()
    // {
    //     return $this->belongsTo("App\User");
    // }

    // public function number()
    // {
    //     return $this->hasOne("Tests\Tmp\Phone");
    // }

    // public function tags()
    // {
    //     return $this->belongsToMany("Tests\Tmp\Tag")->withTimestamps();
    // }

}