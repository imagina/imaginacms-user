<?php

namespace Modules\User\Entities\Sentinel;


use Cartalyst\Sentinel\Laravel\Facades\Activation;
use Cartalyst\Sentinel\Users\EloquentUser;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Laracasts\Presenter\PresentableTrait;
use Modules\Core\Icrud\Traits\HasCacheClearable;
use Modules\Iqreable\Traits\IsQreable;
use Modules\Isite\Traits\Tokenable;
use Modules\Notification\Traits\IsNotificable;
use Modules\User\Entities\UserInterface;
use Modules\User\Entities\UserToken;
use Modules\User\Presenters\UserPresenter;
use Laravel\Passport\HasApiTokens;
use Modules\User\Services\UserResetter;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Modules\Isite\Traits\RevisionableTrait;
use Modules\Media\Support\Traits\MediaRelation;
use Illuminate\Support\Facades\Auth;

use Modules\Core\Support\Traits\AuditTrait;

use Modules\Rateable\Traits\Rateable;

class User extends EloquentUser implements UserInterface, AuthenticatableContract
{
  use PresentableTrait, Authenticatable, HasApiTokens, AuditTrait, RevisionableTrait, Tokenable, MediaRelation,
    Rateable, IsQreable, HasCacheClearable, IsNotificable;

  public $repository = 'Modules\User\Repositories\UserRepository';
  public $entity = 'Modules\User\Entities\Sentinel\User';

  protected $fillable = [
    'email',
    'password',
    'permissions',
    'first_name',
    'last_name',
    'timezone',
    'language',
    'is_guest',
    'user_name',
    'phone'
  ];

  /**
   * {@inheritDoc}
   */
  protected $loginNames = ['email'];

  protected $presenter = UserPresenter::class;

  public function __construct(array $attributes = [])
  {
    $this->loginNames = setting('iprofile::customLogin', null, config('asgard.user.config.login-columns'));

    if (!is_array($this->loginNames)) {
      $this->loginNames = json_decode($this->loginNames);
    }
    if (config()->has('asgard.user.config.presenter')) {
      $this->presenter = config('asgard.user.config.presenter', UserPresenter::class);
    }
//        if (config()->has('asgard.user.config.dates')) {
//            $this->dates = config('asgard.user.config.dates', []);
//        }
    if (config()->has('asgard.user.config.casts')) {
      $this->casts = config('asgard.user.config.casts', []);
    }

    parent::__construct($attributes);
  }

  /**
   * {@inheritdoc}
   */
  public function hasRoleId($roleId)
  {
    return $this->roles()->whereId($roleId)->count() >= 1;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRoleSlug($slug)
  {
    return $this->roles()->whereSlug($slug)->count() >= 1;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRoleName($name)
  {
    return $this->roles()->whereName($name)->count() >= 1;
  }

  /**
   * {@inheritdoc}
   */
  public function isActivated()
  {
    if (is_int($this->getKey()) && Activation::completed($this)) {
      return true;
    }

    return false;
  }

  public function api_keys()
  {
    return $this->hasMany(UserToken::class);
  }

  /**
   * {@inheritdoc}
   */
  public function getFirstApiKey()
  {
    $userToken = $this->api_keys->first();

    if ($userToken === null) {
      return '';
    }

    return $userToken->access_token;
  }

  public function organizations()
  {
    return $this->belongsToMany(
      \Modules\Itenant\Entities\Organization::class,
      'itenant__user_organization');
  }

  public function addresses()
  {
    return $this->hasMany(
      \Modules\Iprofile\Entities\Address::class);
  }

  public function fields()
  {
    return $this->hasMany(
      \Modules\Iprofile\Entities\Field::class);
  }

  public function settings()
  {
    return $this->hasMany(
      \Modules\Iprofile\Entities\Setting::class, 'related_id')->where('entity_name', 'user');
  }

  public function departments()
  {
    return $this->belongsToMany(
      \Modules\Iprofile\Entities\Department::class,
      'iprofile__user_department');
  }

  public function __call($method, $parameters)
  {
    //i: Convert array to dot notation
    $config = implode('.', ['asgard.user.config.relations', $method]);

    //i: Relation method resolver
    if (config()->has($config)) {
      $function = config()->get($config);
      $bound = $function->bindTo($this);

      return $bound();
    }

    //i: No relation found, return the call to parent (Eloquent) to handle it.
    return parent::__call($method, $parameters);
  }


  /**
   * {@inheritdoc}
   */
  public function hasAccess($permission)
  {
    $permissions = $this->getPermissionsInstance();

    return $permissions->hasAccess($permission);
  }

  public function information()
  {
    return $this->hasMany(
      \Modules\Iprofile\Entities\Information::class, 'user_id');
  }

  public function skills()
  {
    return $this->hasMany(
      \Modules\Iprofile\Entities\Skill::class, 'user_id');

  }

  public function getCacheClearableData()
  {
    $baseUrls = [config("app.url")];

    if (!$this->wasRecentlyCreated && !$this->is_internal) {
      $baseUrls[] = $this->url;
    }
    $urls = ['urls' => $baseUrls];

    return $urls;
  }

  public function getUrlAttribute()
  {
    $url = url('/account/profile/' . $this->id);
    return $url;
  }

  /**
   * Make Notificable Params | to Trait
   * @param $event (created|updated|deleted)
   */
  public function isNotificableParams($event)
  {
    $response = [];


    if ($event == "created") {
      //Validation Event Created and notifyUserOnCreate
      $notifyUserOnCreation = setting("iprofile::notifyUserOnCreation", null, 0);
      if ($notifyUserOnCreation) {
        $userId = Auth::id() ?? null;
        $auth = app("Modules\\User\\Contracts\\Authentication");
        $code = $auth->createReminderCode($this);
        $response[$event] = [
          'title' => trans('iprofile::iprofile.notifications.titleChangePassword'),
          'message' => trans('iprofile::iprofile.notifications.messageChangePassword', [
            'linkPassword' => url("/" . config('asgard.iprofile.config.resetCompletePasswordPath') . "/{$this->id}/{$code}")
          ]),
          "email" => [$this->email],
          "userId" => $userId,
          "source" => "createUser",
        ];
      } else {
        $response[$event] = [
          'title' => trans('iprofile::iprofile.welcomeTitleEmail'),
          'message' => trans('iprofile::iprofile.welcomeMessageEmail', [
            'userName' => $this->first_name,
            'appName' => env('APP_NAME')
          ]),
          "email" => [$this->email],
          "userId" => $this->id,
          "source" => "welcomeUser"
        ];
      }
    }

    return $response;

  }
}
