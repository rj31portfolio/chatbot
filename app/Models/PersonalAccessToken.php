<?php
namespace App\Models;
class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    protected $fillable=['business_id','name','token','abilities','expires_at'];
}
