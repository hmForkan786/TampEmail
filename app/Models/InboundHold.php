<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InboundHold extends BaseModel
{
    protected $table = 'inbound_holds';
    protected $fillable = ['target_type','target_id','held_by_user_id','reason','held_until','released_at','released_by_user_id'];
    protected function casts(): array { return array_merge(parent::casts(), ['held_until'=>'datetime','released_at'=>'datetime']); }
    public function heldBy(): BelongsTo { return $this->belongsTo(User::class,'held_by_user_id'); }
    public function releasedBy(): BelongsTo { return $this->belongsTo(User::class,'released_by_user_id'); }
    public function target(): IlluminateDatabaseEloquentRelationsMorphTo { return $this->morphTo(); }
    #[Scope] protected function active(Builder $query): void { $query->whereNull('released_at')->where(fn (Builder $q) => $q->whereNull('held_until')->orWhere('held_until','>',now())); }
    public function isActive(): bool { return $this->released_at === null && ($this->held_until === null || $this->held_until->isFuture()); }
}
