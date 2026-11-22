<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasUuids, HasFactory, Notifiable;
    protected $attributes = ['status' => 'active'];
    protected $fillable = ['email_normalized', 'display_name', 'email_verified_at', 'locale', 'status', 'last_login_at'];
    protected function casts(): array { return ['email_verified_at'=>'datetime','last_login_at'=>'datetime']; }
    public function memberships() { return $this->hasMany(Membership::class); }
    public function routeNotificationForMail(): string { return $this->email_normalized; }
}
