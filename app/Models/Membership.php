<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Membership extends Model
{
    use HasUuids;
    protected $fillable = ['organization_id','user_id','role','status','joined_at'];
    protected function casts(): array { return ['joined_at'=>'datetime']; }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function user() { return $this->belongsTo(User::class); }
}
